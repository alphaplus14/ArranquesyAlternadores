<?php

use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\Reverso;
use App\Models\Unidad;
use App\Models\VentaItem;

/*
 * S-43 (RF-06, RF-07, regla 8, regla 12): solo el mostrador vende. El admin consulta ventas y las
 * reversa con motivo; abrir, escanear, cerrar y cambiar precios le responde 403.
 */

beforeEach(fn () => sembrarDemo());

/** Venta a crédito abierta de un mostrador con 2 unidades; devuelve [ventaId, ids, mostrador, código]. */
function ventaDeMostradorS43(): array
{
    $codigo = 'S43-'.random_int(1000, 9999);
    refS38($codigo, 2, 50000);
    $mostrador = actuarComo(Rol::Mostrador);
    $ventaId = test()->postJson('/api/ventas', ['forma_pago' => 'credito', 'cliente_id' => Cliente::factory()->create()->id])->assertCreated()->json('id');
    $ids = collect(test()->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => $codigo, 'metodo_captura' => 'escaneado', 'cantidad' => 2])
        ->assertCreated()->json('items'))->pluck('id')->all();

    return [$ventaId, $ids, $mostrador, $codigo];
}

it('S-43: el admin recibe 403 al abrir una venta', function () {
    actuarComo(Rol::Admin);

    $this->postJson('/api/ventas')->assertForbidden()->assertJsonPath('message', 'Su rol no tiene acceso a este módulo.');
    $this->postJson('/api/ventas', ['forma_pago' => 'credito', 'cliente_id' => Cliente::factory()->create()->id])->assertForbidden();
});

it('S-43: el admin recibe 403 al escanear en una venta y no descarga la unidad', function () {
    [$ventaId, , , $codigo] = ventaDeMostradorS43();
    $disponibles = Unidad::where('estado', 'disponible')->count();

    actuarComo(Rol::Admin);
    $this->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => $codigo, 'metodo_captura' => 'digitado'])->assertForbidden();
    expect(Unidad::where('estado', 'disponible')->count())->toBe($disponibles);
});

it('S-43: el admin recibe 403 al cerrar una venta', function () {
    [$ventaId] = ventaDeMostradorS43();

    actuarComo(Rol::Admin);
    $this->postJson("/api/ventas/{$ventaId}/cerrar")->assertForbidden();
    $this->getJson("/api/ventas/{$ventaId}")->assertOk()->assertJsonPath('estado', 'abierta');
});

it('S-43: el admin recibe 403 al cambiar precios, por ítem y por lote', function () {
    [$ventaId, $ids] = ventaDeMostradorS43();

    actuarComo(Rol::Admin);
    $this->patchJson("/api/ventas/{$ventaId}/items/{$ids[0]}", ['precio_unitario' => 40000])->assertForbidden();
    $this->patchJson("/api/ventas/{$ventaId}/items/precio", ['ids' => $ids, 'precio_unitario' => 40000])->assertForbidden();
    expect(VentaItem::whereKey($ids)->pluck('precio_unitario')->unique()->values()->all())->toBe(['50000.00']);
});

it('S-43: el admin consulta ventas: GET /ventas (las abiertas de todos) y GET /ventas/{venta}', function () {
    [$ventaId] = ventaDeMostradorS43();

    actuarComo(Rol::Admin);
    expect(collect($this->getJson('/api/ventas?estado=abierta')->assertOk()->json('data'))->pluck('id'))->toContain($ventaId);
    $this->getJson("/api/ventas/{$ventaId}")->assertOk()->assertJsonCount(2, 'items');
});

it('S-43: el mostrador sigue viendo solo sus ventas abiertas', function () {
    [$ventaId] = ventaDeMostradorS43();

    actuarComo(Rol::Mostrador);
    expect(collect($this->getJson('/api/ventas?estado=abierta')->assertOk()->json('data'))->pluck('id'))->not->toContain($ventaId);
});

it('S-43: el admin anula un ítem de una venta abierta con motivo y queda auditado con su usuario y fecha', function () {
    [$ventaId, $ids] = ventaDeMostradorS43();

    $admin = actuarComo(Rol::Admin);
    $this->postJson("/api/ventas/{$ventaId}/reversos", ['tipo' => 'anulacion', 'motivo' => 'Se cobró de más', 'venta_item_id' => $ids[0]])->assertOk();

    $r = Reverso::where('venta_id', $ventaId)->sole();
    expect($r->tipo->value)->toBe('anulacion')
        ->and($r->motivo)->toBe('Se cobró de más')
        ->and($r->usuario_id)->toBe($admin->id)
        ->and($r->created_at)->not->toBeNull();
});

it('S-43: el admin devuelve una venta cerrada con motivo y queda auditado; sin motivo es 422', function () {
    [$ventaId] = ventaDeMostradorS43();
    test()->postJson("/api/ventas/{$ventaId}/cerrar")->assertOk();

    $admin = actuarComo(Rol::Admin);
    $this->postJson("/api/ventas/{$ventaId}/reversos", ['tipo' => 'devolucion'])->assertUnprocessable()->assertJsonValidationErrors('motivo');
    $this->postJson("/api/ventas/{$ventaId}/reversos", ['tipo' => 'devolucion', 'motivo' => 'El cliente devolvió todo'])->assertOk();

    $r = Reverso::where('venta_id', $ventaId)->sole();
    expect($r->tipo->value)->toBe('devolucion')
        ->and($r->motivo)->toBe('El cliente devolvió todo')
        ->and($r->usuario_id)->toBe($admin->id)
        ->and($r->created_at)->not->toBeNull();
});

it('S-43: un mostrador ajeno sigue recibiendo 403 al cambiar precios de una venta que no es suya', function () {
    [$ventaId, $ids] = ventaDeMostradorS43();

    actuarComo(Rol::Mostrador);
    $this->patchJson("/api/ventas/{$ventaId}/items/precio", ['ids' => $ids, 'precio_unitario' => 40000])->assertForbidden();
});
