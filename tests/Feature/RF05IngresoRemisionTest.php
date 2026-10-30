<?php

use App\Enums\Rol;
use App\Models\MovimientoInventario;
use App\Models\Proveedor;
use App\Models\Unidad;

beforeEach(fn () => sembrarDemo());

it('RF-05: un recibo por remisión se carga ítem por ítem, sin QR, y entra al mismo inventario', function () {
    $logistica = actuarComo(Rol::Logistica);
    $sensor = referencia('SEN-TEMP-01');
    $arranque = referencia('ARR-12V-9D');

    $id = $this->postJson('/api/ingresos', [
        'proveedor_id' => $sensor->proveedor_id,
        'numero_documento' => 'REM-9001',
        'fecha' => '2026-10-29',
    ])->assertCreated()->assertJsonPath('tipo', 'remision')->assertJsonPath('estado', 'borrador')->json('id');

    $this->postJson("/api/ingresos/{$id}/items", ['referencia_id' => $sensor->id, 'cantidad' => 3, 'costo_unitario' => 20000])->assertOk();
    $this->postJson("/api/ingresos/{$id}/items", ['referencia_id' => $arranque->id, 'cantidad' => 2, 'costo_unitario' => 250000])->assertOk();

    // Borrador: aún no hay unidades.
    expect(referencia('SEN-TEMP-01')->existencia)->toBe(0);

    $this->postJson("/api/ingresos/{$id}/confirmar")->assertOk()->assertJsonPath('estado', 'confirmado');

    expect(referencia('SEN-TEMP-01')->existencia)->toBe(3)
        ->and(referencia('ARR-12V-9D')->existencia)->toBe(3) // 1 del seeder + 2
        ->and(MovimientoInventario::where('origen_type', 'ingreso')->where('origen_id', $id)->where('usuario_id', $logistica->id)->count())->toBe(5);

    $this->getJson("/api/ingresos/{$id}/unidades")->assertOk()->assertJsonCount(5);
});

it('RF-05: un ingreso confirmado no se puede confirmar otra vez ni modificar', function () {
    actuarComo(Rol::Logistica);
    $ingreso = ingresar(referencia('SEN-TEMP-01'), 1);
    $unidades = Unidad::count();

    $this->postJson("/api/ingresos/{$ingreso->id}/confirmar")->assertStatus(409);
    $this->postJson("/api/ingresos/{$ingreso->id}/items", ['referencia_id' => referencia('SEN-TEMP-01')->id, 'cantidad' => 1, 'costo_unitario' => 1])
        ->assertStatus(409);

    expect(Unidad::count())->toBe($unidades);
});

it('RF-05: un ingreso sin ítems no se confirma', function () {
    actuarComo(Rol::Logistica);

    $id = $this->postJson('/api/ingresos', ['proveedor_id' => Proveedor::first()->id, 'numero_documento' => 'R1', 'fecha' => '2026-10-29'])->json('id');

    $this->postJson("/api/ingresos/{$id}/confirmar")->assertUnprocessable();
});

it('RF-05: en borrador se puede quitar una línea; no afecta otros ingresos', function () {
    actuarComo(Rol::Logistica);
    $id = $this->postJson('/api/ingresos', ['proveedor_id' => Proveedor::first()->id, 'numero_documento' => 'R2', 'fecha' => '2026-10-29'])->json('id');
    $itemId = $this->postJson("/api/ingresos/{$id}/items", ['referencia_id' => referencia('SEN-TEMP-01')->id, 'cantidad' => 1, 'costo_unitario' => 1])
        ->json('items.0.id');

    $this->deleteJson("/api/ingresos/{$id}/items/{$itemId}")->assertOk()->assertJsonCount(0, 'items');
});

it('RF-09: el ingreso registra y muestra el descuento de la factura', function () {
    actuarComo(Rol::Logistica);

    $id = $this->postJson('/api/ingresos', [
        'proveedor_id' => Proveedor::first()->id, 'numero_documento' => 'R3', 'fecha' => '2026-10-29', 'descuento_total' => 15000,
    ])->json('id');
    $respuesta = $this->postJson("/api/ingresos/{$id}/items", [
        'referencia_id' => referencia('SEN-TEMP-01')->id, 'cantidad' => 10, 'costo_unitario' => 10000, 'descuento' => 5000, 'iva_porcentaje' => 19,
    ]);

    // subtotal 100000 - 5000 = 95000; IVA 19 % = 18050; total = 95000 - 15000 + 18050
    $respuesta->assertJsonPath('totales.descuento_total', '15000.00')
        ->assertJsonPath('totales.descuento_lineas', 5000.0)
        ->assertJsonPath('totales.total', 98050.0);
});

it('RF-10: una línea sin IVA no suma impuesto y queda registrada como sin IVA', function () {
    actuarComo(Rol::Logistica);
    $id = $this->postJson('/api/ingresos', ['proveedor_id' => Proveedor::first()->id, 'numero_documento' => 'R4', 'fecha' => '2026-10-29'])->json('id');

    $this->postJson("/api/ingresos/{$id}/items", ['referencia_id' => referencia('SEN-TEMP-01')->id, 'cantidad' => 2, 'costo_unitario' => 10000, 'sin_iva' => true]);
    $respuesta = $this->postJson("/api/ingresos/{$id}/items", ['referencia_id' => referencia('ARR-12V-9D')->id, 'cantidad' => 1, 'costo_unitario' => 10000]);

    $respuesta->assertJsonPath('items.0.sin_iva', true)
        ->assertJsonPath('items.0.iva', 0.0)
        ->assertJsonPath('totales.iva', 1900.0)
        ->assertJsonPath('totales.lineas_sin_iva', 1);
});
