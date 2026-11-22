<?php

use App\Enums\Rol;
use App\Enums\TipoIngreso;
use App\Exceptions\RegistroInmutableException;
use App\Models\Auditoria;
use App\Models\Ingreso;
use App\Models\Referencia;
use App\Models\Unidad;
use App\Models\User;
use App\Services\IngresoService;

beforeEach(function () {
    sembrarDemo();
    $this->logistica = actuarComo(Rol::Logistica);
});

/** Factura en borrador del proveedor de la referencia, con una línea si se indica cantidad. */
function facturaLigable(Referencia $referencia, ?int $cantidad = null, ?User $usuario = null): Ingreso
{
    $servicio = app(IngresoService::class);
    $factura = $servicio->crearBorrador([
        'tipo' => TipoIngreso::Factura,
        'proveedor_id' => $referencia->proveedor_id,
        'numero_documento' => 'FEV-'.random_int(1000, 9999),
        'fecha' => now()->toDateString(),
    ], $usuario ?? User::factory()->rol(Rol::Logistica)->create());

    if ($cantidad !== null) {
        $servicio->agregarItem($factura, ['referencia_id' => $referencia->id, 'cantidad' => $cantidad, 'costo_unitario' => 1000]);
    }

    return $factura;
}

function unidadesDelIngreso(Ingreso $ingreso): int
{
    return Unidad::whereIn('ingreso_item_id', $ingreso->items()->select('id'))->count();
}

it('RF-19: remisión de 4 y factura ligada de 4: al confirmar no se crean unidades y la existencia sigue igual', function () {
    $ref = referencia('RN-4410'); // 2 del sembrado
    $remision = ingresar($ref, 4);
    $factura = facturaLigable($ref, 4);

    $this->postJson("/api/ingresos/{$factura->id}/remisiones", ['remision_ids' => [$remision->id]])->assertOk()
        ->assertJsonPath('remisiones.0.id', $remision->id);
    $this->postJson("/api/ingresos/{$factura->id}/confirmar")->assertOk()
        ->assertJsonPath('items.0.cantidad_cubierta', 4);

    expect(unidadesDelIngreso($factura))->toBe(0)
        ->and($ref->fresh()->existencia)->toBe(6)
        ->and(unidadesDelIngreso($remision))->toBe(4);
});

it('RF-19: factura de 5 contra remisión de 4 crea 1 unidad nueva y la existencia pasa a 5 de ese ingreso', function () {
    $ref = referencia('RN-4410');
    $remision = ingresar($ref, 4);
    $factura = facturaLigable($ref, 5);

    $this->postJson("/api/ingresos/{$factura->id}/remisiones", ['remision_ids' => [$remision->id]])->assertOk();
    $this->postJson("/api/ingresos/{$factura->id}/confirmar")->assertOk();

    expect(unidadesDelIngreso($factura))->toBe(1)
        ->and($ref->fresh()->existencia)->toBe(2 + 5);
});

it('RF-19: factura de 3 contra remisión de 4 exige aceptar la diferencia con motivo y la deja registrada', function () {
    $ref = referencia('RN-4410');
    $remision = ingresar($ref, 4);
    $factura = facturaLigable($ref, 3);
    $this->postJson("/api/ingresos/{$factura->id}/remisiones", ['remision_ids' => [$remision->id]])->assertOk();

    $this->postJson("/api/ingresos/{$factura->id}/confirmar")->assertUnprocessable()
        ->assertJsonPath('motivo', 'diferencia_remision')
        ->assertJsonPath('diferencias.0.codigo_referencia', 'RN-4410')
        ->assertJsonPath('diferencias.0.recibido', 4)
        ->assertJsonPath('diferencias.0.facturado', 3);
    $this->postJson("/api/ingresos/{$factura->id}/confirmar", ['aceptar_diferencia' => true])->assertUnprocessable()
        ->assertJsonValidationErrors('motivo');
    expect($factura->fresh()->esBorrador())->toBeTrue();

    $this->postJson("/api/ingresos/{$factura->id}/confirmar", ['aceptar_diferencia' => true, 'motivo' => 'El proveedor factura 1 después'])
        ->assertOk()
        ->assertJsonPath('diferencia_aceptada', true)
        ->assertJsonPath('diferencia_motivo', 'El proveedor factura 1 después')
        ->assertJsonPath('confirmado_por.id', $this->logistica->id);

    expect(unidadesDelIngreso($factura))->toBe(0)->and($ref->fresh()->existencia)->toBe(6);
});

it('RF-19: una referencia de la remisión que no aparece en la factura también es una diferencia', function () {
    $ref = referencia('RN-4410');
    $otra = referencia('F00M144136'); // mismo proveedor
    $remision = ingresar($ref, 2);
    $factura = facturaLigable($otra, 1);
    $this->postJson("/api/ingresos/{$factura->id}/remisiones", ['remision_ids' => [$remision->id]])->assertOk();

    $this->postJson("/api/ingresos/{$factura->id}/confirmar")->assertUnprocessable()
        ->assertJsonPath('diferencias.0.codigo_referencia', 'RN-4410')
        ->assertJsonPath('diferencias.0.facturado', 0);
});

it('RF-19: no se liga una remisión de otro proveedor, ya ligada a otra factura o sin confirmar', function () {
    $ref = referencia('RN-4410');
    $factura = facturaLigable($ref, 1);

    $deOtro = ingresar(referencia('DE-IM204-A'), 1);
    $this->postJson("/api/ingresos/{$factura->id}/remisiones", ['remision_ids' => [$deOtro->id]])->assertUnprocessable();

    $ligada = ingresar($ref, 1);
    $otraFactura = facturaLigable($ref, 1);
    app(IngresoService::class)->ligarRemisiones($otraFactura, [$ligada->id]);
    $this->postJson("/api/ingresos/{$factura->id}/remisiones", ['remision_ids' => [$ligada->id]])->assertUnprocessable();

    $borrador = app(IngresoService::class)->crearBorrador([
        'proveedor_id' => $ref->proveedor_id, 'numero_documento' => 'REM-B1', 'fecha' => now()->toDateString(),
    ], $this->logistica);
    $this->postJson("/api/ingresos/{$factura->id}/remisiones", ['remision_ids' => [$borrador->id]])->assertUnprocessable();

    expect($factura->remisiones()->count())->toBe(0);
});

it('RF-19: solo se liga a una factura, no a una remisión', function () {
    $ref = referencia('RN-4410');
    $remision = ingresar($ref, 1);
    $otraRemision = app(IngresoService::class)->crearBorrador([
        'proveedor_id' => $ref->proveedor_id, 'numero_documento' => 'REM-X', 'fecha' => now()->toDateString(),
    ], $this->logistica);

    $this->postJson("/api/ingresos/{$otraRemision->id}/remisiones", ['remision_ids' => [$remision->id]])->assertUnprocessable();
});

it('RF-19: las remisiones disponibles son las confirmadas del proveedor sin factura, por fecha', function () {
    $ref = referencia('RN-4410');
    $factura = facturaLigable($ref, 1);
    $libre = ingresar($ref, 1);
    $ligada = ingresar($ref, 1);
    app(IngresoService::class)->ligarRemisiones(facturaLigable($ref, 1), [$ligada->id]);
    ingresar(referencia('DE-IM204-A'), 1);

    $ids = collect($this->getJson("/api/ingresos/{$factura->id}/remisiones-disponibles")->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($libre->id)->not->toContain($ligada->id);
});

it('RF-19: el prellenado suma por referencia las líneas de varias remisiones y no duplica al repetirlo', function () {
    $ref = referencia('RN-4410');
    $otra = referencia('F00M144136');
    $r1 = ingresar($ref, 2);
    $r2 = ingresar($ref, 3);
    $r3 = ingresar($otra, 1);
    $factura = facturaLigable($ref);

    $this->postJson("/api/ingresos/{$factura->id}/remisiones", ['remision_ids' => [$r1->id, $r2->id, $r3->id]])->assertOk();
    $this->postJson("/api/ingresos/{$factura->id}/prellenar")->assertOk();
    $this->postJson("/api/ingresos/{$factura->id}/prellenar")->assertOk();

    $lineas = $factura->items()->get()->keyBy('referencia_id');
    expect($lineas)->toHaveCount(2)
        ->and($lineas[$ref->id]->cantidad)->toBe(5)
        ->and($lineas[$otra->id]->cantidad)->toBe(1)
        ->and($lineas[$ref->id]->costo_unitario)->toBe('1000.00');
});

it('RF-19: en borrador se desliga; confirmada la factura, el vínculo es definitivo', function () {
    $ref = referencia('RN-4410');
    $remision = ingresar($ref, 1);
    $factura = facturaLigable($ref, 1);

    $this->postJson("/api/ingresos/{$factura->id}/remisiones", ['remision_ids' => [$remision->id]])->assertOk();
    $this->deleteJson("/api/ingresos/{$factura->id}/remisiones/{$remision->id}")->assertOk()->assertJsonPath('remisiones', []);
    expect($remision->fresh()->factura_id)->toBeNull();

    $this->postJson("/api/ingresos/{$factura->id}/remisiones", ['remision_ids' => [$remision->id]])->assertOk();
    $this->postJson("/api/ingresos/{$factura->id}/confirmar")->assertOk();

    $this->deleteJson("/api/ingresos/{$factura->id}/remisiones/{$remision->id}")->assertStatus(409);
    $remision = $remision->fresh();
    $remision->factura_id = null;
    expect(fn () => $remision->save())->toThrow(RegistroInmutableException::class);
});

it('RF-19 / RF-11: ligar y desligar quedan en la auditoría', function () {
    $ref = referencia('RN-4410');
    $remision = ingresar($ref, 1);
    $factura = facturaLigable($ref, 1);

    $this->postJson("/api/ingresos/{$factura->id}/remisiones", ['remision_ids' => [$remision->id]])->assertOk();
    $this->deleteJson("/api/ingresos/{$factura->id}/remisiones/{$remision->id}")->assertOk();

    $filas = Auditoria::where('tabla', 'ingresos')->where('registro_id', $remision->id)->where('campo', 'factura_id')->orderBy('id')->get();
    expect($filas)->toHaveCount(2)
        ->and($filas[0]->valor_anterior)->toBeNull()
        ->and($filas[0]->valor_nuevo)->toBe((string) $factura->id)
        ->and($filas[0]->usuario_id)->toBe($this->logistica->id)
        ->and($filas[1]->valor_nuevo)->toBeNull();
});

it('RF-19: una remisión confirmada sigue sin poder editarse en otro campo', function () {
    $remision = ingresar(referencia('RN-4410'), 1);

    $remision->numero_documento = 'OTRO';
    expect(fn () => $remision->save())->toThrow(RegistroInmutableException::class);
});
