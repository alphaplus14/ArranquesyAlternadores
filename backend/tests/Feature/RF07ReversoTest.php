<?php

use App\Enums\Rol;
use App\Exceptions\RegistroInmutableException;
use App\Models\Reverso;
use App\Models\VentaItem;

beforeEach(fn () => sembrarDemo());

/** Abre una venta, escanea la unidad y, si se pide, la cierra. Devuelve [ventaId, ventaItemId]. */
function venderUnidad(string $codigoReferencia, bool $cerrar = true): array
{
    $ventaId = test()->postJson('/api/ventas')->json('id');
    $itemId = test()->postJson("/api/ventas/{$ventaId}/escanear", [
        'codigo_barras' => unidadDe($codigoReferencia)->codigo_barras,
        'metodo_captura' => 'escaneado',
    ])->json('venta_item_id');

    if ($cerrar) {
        test()->postJson("/api/ventas/{$ventaId}/cerrar");
    }

    return [$ventaId, $itemId];
}

it('RF-07: un reverso devuelve la unidad y la venta original sigue visible con quién, cuándo y por qué', function () {
    $mostrador = actuarComo(Rol::Mostrador);
    [$ventaId, $itemId] = venderUnidad('RN-4410');
    expect(referencia('RN-4410')->existencia)->toBe(1);

    $respuesta = $this->postJson("/api/ventas/{$ventaId}/reversos", ['tipo' => 'devolucion', 'motivo' => 'Cliente trajo la pieza equivocada'])
        ->assertOk();

    expect(referencia('RN-4410')->existencia)->toBe(2)
        ->and(VentaItem::find($itemId))->not->toBeNull();

    $respuesta->assertJsonPath('id', $ventaId)
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.id', $itemId)
        ->assertJsonPath('reversos.0.tipo', 'devolucion')
        ->assertJsonPath('reversos.0.motivo', 'Cliente trajo la pieza equivocada')
        ->assertJsonPath('reversos.0.usuario.id', $mostrador->id)
        ->assertJsonPath('reversos.0.venta_item_ids', [$itemId]);
    expect($respuesta->json('reversos.0.created_at'))->not->toBeNull();

    // Las dos operaciones quedan en el historial.
    $this->assertDatabaseHas('movimientos_inventario', ['tipo' => 'venta', 'origen_type' => 'venta', 'origen_id' => $ventaId]);
    $this->assertDatabaseHas('movimientos_inventario', ['tipo' => 'reverso', 'origen_type' => 'reverso']);
});

it('RF-07: el motivo es obligatorio', function () {
    actuarComo(Rol::Mostrador);
    [$ventaId] = venderUnidad('RN-4410');

    $this->postJson("/api/ventas/{$ventaId}/reversos", ['tipo' => 'anulacion', 'motivo' => ''])
        ->assertUnprocessable()->assertJsonValidationErrors('motivo');
});

it('RF-07: un ítem no se puede reversar dos veces', function () {
    actuarComo(Rol::Mostrador);
    [$ventaId, $itemId] = venderUnidad('RN-4410');

    $this->postJson("/api/ventas/{$ventaId}/reversos", ['tipo' => 'anulacion', 'motivo' => 'Error de digitación', 'venta_item_id' => $itemId])->assertOk();
    $this->postJson("/api/ventas/{$ventaId}/reversos", ['tipo' => 'anulacion', 'motivo' => 'Otra vez', 'venta_item_id' => $itemId])->assertUnprocessable();

    expect(referencia('RN-4410')->existencia)->toBe(2);
});

it('RF-07: la anulación completa reversa todos los ítems pendientes en un solo reverso', function () {
    actuarComo(Rol::Mostrador);
    $ventaId = $this->postJson('/api/ventas')->json('id');
    foreach (['RN-4410', 'AC-77812'] as $ref) {
        $this->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => unidadDe($ref)->codigo_barras, 'metodo_captura' => 'escaneado']);
    }
    $this->postJson("/api/ventas/{$ventaId}/cerrar");

    $this->postJson("/api/ventas/{$ventaId}/reversos", ['tipo' => 'anulacion', 'motivo' => 'Venta registrada por error'])
        ->assertOk()->assertJsonCount(1, 'reversos')->assertJsonCount(2, 'reversos.0.venta_item_ids');

    expect(referencia('RN-4410')->existencia)->toBe(2)->and(referencia('AC-77812')->existencia)->toBe(2);
});

it('RF-07: quitar un ítem de una venta abierta es un reverso, no un DELETE', function () {
    actuarComo(Rol::Mostrador);
    [$ventaId, $itemId] = venderUnidad('RN-4410', cerrar: false);

    $this->postJson("/api/ventas/{$ventaId}/reversos", ['tipo' => 'anulacion', 'motivo' => 'El cliente no la llevó', 'venta_item_id' => $itemId])
        ->assertOk()->assertJsonPath('estado', 'abierta');

    expect(VentaItem::find($itemId))->not->toBeNull()
        ->and(Reverso::where('venta_id', $ventaId)->count())->toBe(1)
        ->and(referencia('RN-4410')->existencia)->toBe(2);
});

it('RF-07: una venta abierta no admite devolución, solo anulación del ítem', function () {
    actuarComo(Rol::Mostrador);
    [$ventaId] = venderUnidad('RN-4410', cerrar: false);

    $this->postJson("/api/ventas/{$ventaId}/reversos", ['tipo' => 'devolucion', 'motivo' => 'x123'])->assertUnprocessable();
});

it('RF-07: la unidad devuelta se puede vender de nuevo', function () {
    actuarComo(Rol::Mostrador);
    $codigo = unidadDe('RN-4410')->codigo_barras;
    [$ventaId] = venderUnidad('RN-4410');
    $this->postJson("/api/ventas/{$ventaId}/reversos", ['tipo' => 'devolucion', 'motivo' => 'Devolución']);

    $nueva = $this->postJson('/api/ventas')->json('id');
    $this->postJson("/api/ventas/{$nueva}/escanear", ['codigo_barras' => $codigo, 'metodo_captura' => 'escaneado'])->assertCreated();
});

it('RF-07: un reverso no se edita ni se borra', function () {
    actuarComo(Rol::Mostrador);
    [$ventaId] = venderUnidad('RN-4410');
    $this->postJson("/api/ventas/{$ventaId}/reversos", ['tipo' => 'devolucion', 'motivo' => 'Devolución']);
    $reverso = Reverso::first();

    expect(fn () => $reverso->update(['motivo' => 'otro']))->toThrow(RegistroInmutableException::class)
        ->and(fn () => $reverso->delete())->toThrow(RegistroInmutableException::class);
});
