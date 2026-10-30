<?php

use App\Enums\Rol;
use App\Exceptions\RegistroInmutableException;
use App\Models\MovimientoInventario;
use App\Models\Venta;
use App\Models\VentaItem;

beforeEach(fn () => sembrarDemo());

it('RF-06: cada escaneo descuenta de inmediato, antes de cerrar la venta, y deja el usuario que vendió', function () {
    $mostrador = actuarComo(Rol::Mostrador);
    $ventaId = $this->postJson('/api/ventas')->assertCreated()->assertJsonPath('estado', 'abierta')->json('id');
    $unidad = unidadDe('RN-4410');

    $this->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => $unidad->codigo_barras, 'metodo_captura' => 'escaneado'])
        ->assertCreated()
        ->assertJsonPath('referencia.existencia', 1);

    // La venta sigue abierta y el inventario ya bajó.
    expect(Venta::find($ventaId)->estaAbierta())->toBeTrue()
        ->and(referencia('RN-4410')->existencia)->toBe(1)
        ->and($unidad->fresh()->estado->value)->toBe('vendida')
        ->and(VentaItem::where('venta_id', $ventaId)->value('usuario_id'))->toBe($mostrador->id)
        ->and(MovimientoInventario::where('tipo', 'venta')->where('unidad_id', $unidad->id)->value('usuario_id'))->toBe($mostrador->id);
});

it('RF-06: una venta baja la unidad escaneada y baja el total del universal', function () {
    actuarComo(Rol::Mostrador);
    $ventaId = $this->postJson('/api/ventas')->json('id');

    $this->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => unidadDe('AC-77812')->codigo_barras, 'metodo_captura' => 'escaneado']);

    $im204 = referencia('AC-77812')->codigo_universal_id;
    expect($this->getJson("/api/codigos-universales/{$im204}")->json('existencia_total'))->toBe(5);
});

it('RF-06: escanear una unidad ya vendida responde 409 y no descuenta dos veces', function () {
    actuarComo(Rol::Mostrador);
    $ventaId = $this->postJson('/api/ventas')->json('id');
    $codigo = unidadDe('RN-4410')->codigo_barras;

    $this->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => $codigo, 'metodo_captura' => 'escaneado'])->assertCreated();
    $this->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => $codigo, 'metodo_captura' => 'escaneado'])->assertStatus(409);

    expect(referencia('RN-4410')->existencia)->toBe(1);
});

it('RF-06: un código inexistente responde 404', function () {
    actuarComo(Rol::Mostrador);
    $ventaId = $this->postJson('/api/ventas')->json('id');

    $this->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => 'NOEXISTE', 'metodo_captura' => 'escaneado'])->assertNotFound();
});

it('RF-06: una venta cerrada no admite más escaneos', function () {
    actuarComo(Rol::Mostrador);
    $ventaId = $this->postJson('/api/ventas')->json('id');
    $this->postJson("/api/ventas/{$ventaId}/cerrar")->assertOk()->assertJsonPath('estado', 'cerrada');

    $this->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => unidadDe('RN-4410')->codigo_barras, 'metodo_captura' => 'escaneado'])
        ->assertStatus(409);
});

it('RF-06: intentar borrar una venta lanza una excepción', function () {
    $venta = Venta::create(['usuario_id' => actuarComo(Rol::Mostrador)->id]);

    expect(fn () => $venta->delete())->toThrow(RegistroInmutableException::class);
    expect(Venta::find($venta->id))->not->toBeNull();
});

it('RF-06: una venta no se edita salvo para cerrarla', function () {
    $venta = Venta::create(['usuario_id' => actuarComo(Rol::Mostrador)->id]);
    $otro = actuarComo(Rol::Mostrador);

    $venta->usuario_id = $otro->id;
    expect(fn () => $venta->save())->toThrow(RegistroInmutableException::class);
});

it('RF-06: los ítems de venta y los movimientos no se editan ni se borran', function () {
    actuarComo(Rol::Mostrador);
    $ventaId = $this->postJson('/api/ventas')->json('id');
    $this->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => unidadDe('RN-4410')->codigo_barras, 'metodo_captura' => 'escaneado']);

    $item = VentaItem::where('venta_id', $ventaId)->first();
    $movimiento = MovimientoInventario::where('tipo', 'venta')->first();

    expect(fn () => $item->delete())->toThrow(RegistroInmutableException::class)
        ->and(fn () => $item->update(['metodo_captura' => 'digitado']))->toThrow(RegistroInmutableException::class)
        ->and(fn () => $movimiento->delete())->toThrow(RegistroInmutableException::class);
});

it('RF-06: no existe ruta DELETE para ventas', function () {
    actuarComo(Rol::Admin);
    $ventaId = $this->postJson('/api/ventas')->json('id');

    $this->deleteJson("/api/ventas/{$ventaId}")->assertStatus(405);
});
