<?php

use App\Enums\Rol;
use App\Exceptions\RegistroInmutableException;
use App\Models\Auditoria;
use App\Models\Ingreso;
use App\Models\Proveedor;

beforeEach(fn () => sembrarDemo());

function ingresoNuevo(array $cambios = []): array
{
    return [
        'tipo' => 'factura',
        'proveedor_id' => Proveedor::orderBy('id')->value('id'),
        'numero_documento' => 'FEV '.random_int(1000, 9999),
        'fecha' => '2026-11-21',
        ...$cambios,
    ];
}

/** Borrador con una línea de RN-4410 a 50.000; devuelve el id. */
function borradorConLinea(array $cambios = []): int
{
    $id = test()->postJson('/api/ingresos', ingresoNuevo($cambios))->assertCreated()->json('id');
    test()->postJson("/api/ingresos/{$id}/items", [
        'referencia_id' => referencia('RN-4410')->id, 'cantidad' => 2, 'costo_unitario' => 50000,
    ])->assertOk();

    return $id;
}

it('RF-16: un ingreso nuevo es de contado por defecto y sin vencimiento', function () {
    actuarComo(Rol::Logistica);

    $this->postJson('/api/ingresos', ingresoNuevo())
        ->assertCreated()
        ->assertJsonPath('forma_pago', 'contado')
        ->assertJsonPath('vence_el', null)
        ->assertJsonPath('total', null);
});

it('RF-16: a crédito con vencimiento digitado se guarda tal cual', function () {
    actuarComo(Rol::Logistica);

    $this->postJson('/api/ingresos', ingresoNuevo(['forma_pago' => 'credito', 'vence_el' => '2026-12-21']))
        ->assertCreated()
        ->assertJsonPath('forma_pago', 'credito')
        ->assertJsonPath('vence_el', '2026-12-21');
});

it('RF-16: a crédito sin vencimiento responde 422', function () {
    actuarComo(Rol::Logistica);

    $this->postJson('/api/ingresos', ingresoNuevo(['forma_pago' => 'credito']))->assertUnprocessable()->assertJsonValidationErrors('vence_el');
    $this->postJson('/api/ingresos', ingresoNuevo(['forma_pago' => 'credito', 'vence_el' => null]))->assertJsonValidationErrors('vence_el');
});

it('RF-16: a crédito con vencimiento anterior a la fecha del documento responde 422', function () {
    actuarComo(Rol::Logistica);

    $this->postJson('/api/ingresos', ingresoNuevo(['forma_pago' => 'credito', 'vence_el' => '2026-11-20']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('vence_el');

    // El mismo día del documento sí vale.
    $this->postJson('/api/ingresos', ingresoNuevo(['forma_pago' => 'credito', 'vence_el' => '2026-11-21']))->assertCreated();
});

it('RF-16: al editar se validan los valores efectivos (pasar a crédito o mover la fecha)', function () {
    actuarComo(Rol::Logistica);
    $contado = $this->postJson('/api/ingresos', ingresoNuevo())->json('id');
    $credito = $this->postJson('/api/ingresos', ingresoNuevo(['forma_pago' => 'credito', 'vence_el' => '2026-12-01']))->json('id');

    $this->patchJson("/api/ingresos/{$contado}", ['forma_pago' => 'credito'])->assertJsonValidationErrors('vence_el');
    $this->patchJson("/api/ingresos/{$credito}", ['fecha' => '2026-12-02'])->assertJsonValidationErrors('vence_el');
    $this->patchJson("/api/ingresos/{$credito}", ['vence_el' => null])->assertJsonValidationErrors('vence_el');

    expect(Ingreso::find($credito)->vence_el->toDateString())->toBe('2026-12-01');
});

it('RF-16: de contado el vencimiento se guarda en null aunque se envíe', function () {
    actuarComo(Rol::Logistica);

    $id = $this->postJson('/api/ingresos', ingresoNuevo(['forma_pago' => 'contado', 'vence_el' => '2026-12-21']))
        ->assertCreated()
        ->assertJsonPath('vence_el', null)
        ->json('id');
    expect(Ingreso::find($id)->vence_el)->toBeNull();

    // Pasar un borrador de crédito a contado también borra el vencimiento.
    $credito = $this->postJson('/api/ingresos', ingresoNuevo(['forma_pago' => 'credito', 'vence_el' => '2026-12-21']))->json('id');
    $this->patchJson("/api/ingresos/{$credito}", ['forma_pago' => 'contado', 'vence_el' => '2026-12-30'])
        ->assertOk()
        ->assertJsonPath('vence_el', null);
});

it('RF-16: confirmar fija el total con el cálculo de RF-09', function () {
    actuarComo(Rol::Logistica);
    // 2 × 50.000 = 100.000; descuento 10.000 prorrateado: base 90.000, IVA 17.100, total 107.100.
    $id = borradorConLinea(['forma_pago' => 'credito', 'vence_el' => '2026-12-21', 'descuento_total' => 10000]);

    $this->getJson("/api/ingresos/{$id}")->assertJsonPath('total', null)->assertJsonPath('totales.total', '107100.00');

    $this->postJson("/api/ingresos/{$id}/confirmar")
        ->assertOk()
        ->assertJsonPath('total', '107100.00')
        ->assertJsonPath('forma_pago', 'credito')
        ->assertJsonPath('vence_el', '2026-12-21');

    expect(Ingreso::find($id)->total)->toBe('107100.00');
});

it('RF-16: después de confirmar no cambian forma de pago, vencimiento, descuento ni total', function () {
    actuarComo(Rol::Logistica);
    $id = borradorConLinea(['forma_pago' => 'credito', 'vence_el' => '2026-12-21']);
    $this->postJson("/api/ingresos/{$id}/confirmar")->assertOk();

    $this->patchJson("/api/ingresos/{$id}", ['forma_pago' => 'contado'])->assertStatus(409);
    $this->patchJson("/api/ingresos/{$id}", ['vence_el' => '2027-01-31'])->assertStatus(409);
    $this->patchJson("/api/ingresos/{$id}", ['descuento_total' => 1000])->assertStatus(409);

    $ingreso = Ingreso::find($id);
    expect($ingreso->forma_pago->value)->toBe('credito')
        ->and($ingreso->vence_el->toDateString())->toBe('2026-12-21')
        ->and($ingreso->descuento_total)->toBe('0.00')
        ->and($ingreso->total)->toBe('119000.00');

    // El modelo tampoco deja cambiar el total por fuera de la API.
    expect(fn () => $ingreso->forceFill(['total' => 1])->save())->toThrow(RegistroInmutableException::class);
});

it('RF-16: confirmar un ingreso a crédito sin vencimiento válido responde 422', function () {
    actuarComo(Rol::Logistica);
    $id = borradorConLinea(['forma_pago' => 'credito', 'vence_el' => '2026-12-21']);
    // Un dato inconsistente que no pasó por la API (por ejemplo, de antes de RF-16).
    Ingreso::whereKey($id)->update(['vence_el' => null]);

    $this->postJson("/api/ingresos/{$id}/confirmar")->assertStatus(422)->assertJsonValidationErrors('vence_el');
    expect(Ingreso::find($id)->estado->value)->toBe('borrador');
});

it('RF-11 / RF-16: cambiar forma de pago, vencimiento o descuento de un borrador queda en la auditoría', function () {
    $logistica = actuarComo(Rol::Logistica);
    $id = $this->postJson('/api/ingresos', ingresoNuevo())->json('id');

    $this->patchJson("/api/ingresos/{$id}", ['forma_pago' => 'credito', 'vence_el' => '2026-12-21'])->assertOk();
    $this->patchJson("/api/ingresos/{$id}", ['vence_el' => '2026-12-31', 'descuento_total' => 0])->assertOk();

    $filas = Auditoria::where('tabla', 'ingresos')->where('registro_id', $id)->orderBy('id')->get()->keyBy('campo');
    expect($filas['forma_pago']->valor_anterior)->toBe('contado')
        ->and($filas['forma_pago']->valor_nuevo)->toBe('credito')
        ->and($filas['forma_pago']->usuario_id)->toBe($logistica->id)
        ->and($filas['forma_pago']->created_at)->not->toBeNull()
        ->and($filas['vence_el']->valor_anterior)->toBe('2026-12-21 00:00:00')
        ->and($filas['vence_el']->valor_nuevo)->toBe('2026-12-31 00:00:00');

    actuarComo(Rol::Admin);
    $this->getJson("/api/auditoria?tabla=ingresos&registro_id={$id}")->assertOk()->assertJsonCount(3, 'data');
});

it('RF-11 / RF-16: el total fijado al confirmar queda auditado con el usuario que confirma', function () {
    actuarComo(Rol::Logistica);
    $id = borradorConLinea();

    $quien = actuarComo(Rol::Logistica);
    $this->postJson("/api/ingresos/{$id}/confirmar")->assertOk();

    $total = Auditoria::where('tabla', 'ingresos')->where('registro_id', $id)->where('campo', 'total')->sole();
    $estado = Auditoria::where('tabla', 'ingresos')->where('registro_id', $id)->where('campo', 'estado')->sole();
    expect($total->valor_anterior)->toBeNull()
        ->and($total->valor_nuevo)->toBe('119000.00')
        ->and($total->usuario_id)->toBe($quien->id)
        ->and($estado->valor_nuevo)->toBe('confirmado');
});

it('RF-16: el proveedor expone plazo_dias en el listado y el admin lo edita (auditado)', function () {
    $admin = actuarComo(Rol::Admin);
    $proveedor = Proveedor::orderBy('id')->firstOrFail();

    $this->patchJson("/api/proveedores/{$proveedor->id}", ['plazo_dias' => 45])->assertOk()->assertJsonPath('plazo_dias', 45);
    $this->patchJson("/api/proveedores/{$proveedor->id}", ['plazo_dias' => -1])->assertJsonValidationErrors('plazo_dias');

    actuarComo(Rol::Logistica);
    $fila = collect($this->getJson('/api/proveedores')->assertOk()->json())->firstWhere('id', $proveedor->id);
    expect($fila['plazo_dias'])->toBe(45);

    $id = $this->postJson('/api/ingresos', ingresoNuevo())->json('id');
    $this->getJson("/api/ingresos/{$id}")->assertJsonPath('proveedor.plazo_dias', 45);

    expect(Auditoria::where('tabla', 'proveedores')->where('campo', 'plazo_dias')->value('usuario_id'))->toBe($admin->id);
});

it('RF-16: el plazo del proveedor no calcula el vencimiento al confirmar', function () {
    actuarComo(Rol::Logistica);
    Proveedor::orderBy('id')->firstOrFail()->update(['plazo_dias' => 30]);
    $id = borradorConLinea(['forma_pago' => 'credito', 'vence_el' => '2026-11-25']);

    $this->postJson("/api/ingresos/{$id}/confirmar")->assertOk()->assertJsonPath('vence_el', '2026-11-25');
});
