<?php

use App\Enums\Rol;
use App\Enums\TipoIngreso;
use App\Exceptions\RegistroInmutableException;
use App\Models\Ingreso;
use App\Models\PagoProveedor;
use App\Models\Referencia;
use App\Models\User;
use App\Services\IngresoService;
use Illuminate\Support\Carbon;

beforeEach(fn () => sembrarDemo());

/** Ingreso de una línea sin IVA (total = cantidad × costo); confirmado salvo que se pida borrador. */
function compra(Referencia $referencia, int $cantidad, int $costo, array $encabezado = [], bool $confirmar = true): Ingreso
{
    $usuario = User::factory()->rol(Rol::Logistica)->create();
    $servicio = app(IngresoService::class);

    $ingreso = $servicio->crearBorrador([
        'proveedor_id' => $referencia->proveedor_id,
        'numero_documento' => 'DOC-'.random_int(10000, 99999),
        'fecha' => now()->toDateString(),
        ...$encabezado,
    ], $usuario);
    $servicio->agregarItem($ingreso, ['referencia_id' => $referencia->id, 'cantidad' => $cantidad, 'costo_unitario' => $costo, 'iva_porcentaje' => 0]);

    return $confirmar ? $servicio->confirmar($ingreso, $usuario) : $ingreso;
}

function aCredito(string $vence = '2026-12-31'): array
{
    return ['forma_pago' => 'credito', 'vence_el' => $vence];
}

it('RF-17: ingreso a crédito de 500 y pago de 200 dejan saldo 300; anular el pago lo devuelve a 500', function () {
    $ref = referencia('RN-4410');
    compra($ref, 1, 500, aCredito());
    actuarComo(Rol::Admin);

    $pago = $this->postJson("/api/proveedores/{$ref->proveedor_id}/pagos", ['valor' => 200, 'medio' => 'transferencia'])
        ->assertCreated()->json('id');
    $this->getJson("/api/proveedores/{$ref->proveedor_id}/saldo")->assertOk()
        ->assertJsonPath('cargos', '500.00')
        ->assertJsonPath('saldo', '300.00');

    $this->postJson("/api/pagos/{$pago}/anulaciones", ['motivo' => 'Transferencia devuelta'])->assertCreated();

    $this->getJson("/api/proveedores/{$ref->proveedor_id}/saldo")
        ->assertJsonPath('saldo', '500.00')
        ->assertJsonPath('pagos_recientes.0.id', $pago)
        ->assertJsonPath('pagos_recientes.0.anulacion.motivo', 'Transferencia devuelta');
});

it('RF-16 / RF-17: un ingreso de contado no genera deuda', function () {
    $ref = referencia('RN-4410');
    compra($ref, 1, 500);
    actuarComo(Rol::Admin);

    $this->getJson("/api/proveedores/{$ref->proveedor_id}/saldo")->assertJsonPath('saldo', '0.00');
});

it('RF-19 / RF-17: una remisión ligada a una factura confirmada no suma dos veces a la deuda', function () {
    $ref = referencia('RN-4410');
    $remision = compra($ref, 4, 100, aCredito());
    $factura = compra($ref, 4, 100, ['tipo' => TipoIngreso::Factura, ...aCredito()], confirmar: false);
    app(IngresoService::class)->ligarRemisiones($factura, [$remision->id]);
    actuarComo(Rol::Admin);

    // Con la factura en borrador, la remisión sigue contando.
    $this->getJson("/api/proveedores/{$ref->proveedor_id}/saldo")->assertJsonPath('saldo', '400.00');

    app(IngresoService::class)->confirmar($factura, User::factory()->rol(Rol::Logistica)->create());

    $respuesta = $this->getJson("/api/proveedores/{$ref->proveedor_id}/saldo")->assertJsonPath('saldo', '400.00');
    expect(collect($respuesta->json('documentos'))->pluck('id')->all())->toBe([$factura->id]);
});

it('RF-17 / S-14: los pagos y el saldo del proveedor son solo del admin', function (Rol $rol) {
    $ref = referencia('RN-4410');
    actuarComo($rol);

    $this->postJson("/api/proveedores/{$ref->proveedor_id}/pagos", ['valor' => 10, 'medio' => 'efectivo'])->assertForbidden();
    $this->getJson("/api/proveedores/{$ref->proveedor_id}/saldo")->assertForbidden();
})->with([Rol::Logistica, Rol::Mostrador]);

it('RF-17: un pago no se anula dos veces, es inmutable y valida valor, medio y fecha', function () {
    Carbon::setTestNow('2026-11-21 10:00:00');
    $ref = referencia('RN-4410');
    actuarComo(Rol::Admin);
    $url = "/api/proveedores/{$ref->proveedor_id}/pagos";

    $this->postJson($url, ['valor' => 0, 'medio' => 'efectivo'])->assertJsonValidationErrors('valor');
    $this->postJson($url, ['valor' => 10, 'medio' => 'canje'])->assertJsonValidationErrors('medio');
    $this->postJson($url, ['valor' => 10, 'medio' => 'efectivo', 'fecha' => '2026-11-22'])->assertJsonValidationErrors('fecha');

    $id = $this->postJson($url, ['valor' => 10, 'medio' => 'cheque', 'fecha' => '2026-11-01', 'referencia' => 'CH-77'])->assertCreated()
        ->assertJsonPath('fecha', '2026-11-01')->json('id');
    $this->postJson("/api/pagos/{$id}/anulaciones", ['motivo' => 'Cheque sin fondos'])->assertCreated();
    $this->postJson("/api/pagos/{$id}/anulaciones", ['motivo' => 'Otra vez'])->assertUnprocessable();

    $pago = PagoProveedor::find($id);
    $pago->valor = 5;
    expect(fn () => $pago->save())->toThrow(RegistroInmutableException::class);
    expect(fn () => $pago->delete())->toThrow(RegistroInmutableException::class);
});

it('RF-17 / S-12 / S-13: el pago se aplica FIFO y lo pendiente cae en su rango de días vencidos', function () {
    $ref = referencia('RN-4410');
    Carbon::setTestNow('2026-08-01 10:00:00');
    $vieja = compra($ref, 1, 300, aCredito('2026-08-31'));
    Carbon::setTestNow('2026-11-01 10:00:00');
    compra($ref, 1, 200, aCredito('2026-12-01'));
    Carbon::setTestNow('2026-11-21 10:00:00');
    actuarComo(Rol::Admin);

    $this->postJson("/api/proveedores/{$ref->proveedor_id}/pagos", ['valor' => 100, 'medio' => 'efectivo'])->assertCreated();

    $this->getJson("/api/proveedores/{$ref->proveedor_id}/saldo")
        ->assertJsonPath('saldo', '400.00')
        ->assertJsonPath('vencido', '200.00')
        ->assertJsonPath('rangos.d61_90', '200.00')
        ->assertJsonPath('rangos.al_dia', '200.00')
        ->assertJsonPath('documentos.0.id', $vieja->id)
        ->assertJsonPath('documentos.0.dias_vencido', 82);
});
