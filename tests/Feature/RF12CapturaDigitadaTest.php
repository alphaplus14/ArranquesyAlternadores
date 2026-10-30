<?php

use App\Enums\Rol;
use App\Models\MovimientoInventario;
use App\Models\VentaItem;

beforeEach(fn () => sembrarDemo());

it('RF-12: una venta con código digitado descuenta la misma unidad que el escaneo y queda marcada como digitada', function () {
    actuarComo(Rol::Mostrador);
    $unidad = unidadDe('RN-4410');
    $ventaId = $this->postJson('/api/ventas')->json('id');

    $this->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => $unidad->codigo_barras, 'metodo_captura' => 'digitado'])
        ->assertCreated()
        ->assertJsonPath('unidad.id', $unidad->id)
        ->assertJsonPath('metodo_captura', 'digitado');

    expect(VentaItem::where('venta_id', $ventaId)->value('metodo_captura')->value)->toBe('digitado')
        ->and(MovimientoInventario::where('tipo', 'venta')->where('unidad_id', $unidad->id)->value('metodo_captura')->value)->toBe('digitado')
        ->and(referencia('RN-4410')->existencia)->toBe(1);
});

it('RF-12: el escaneo queda marcado como escaneado', function () {
    actuarComo(Rol::Mostrador);
    $ventaId = $this->postJson('/api/ventas')->json('id');

    $this->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => unidadDe('RN-4410')->codigo_barras, 'metodo_captura' => 'escaneado'])
        ->assertJsonPath('metodo_captura', 'escaneado');
});

it('RF-12: metodo_captura es obligatorio y solo admite escaneado o digitado', function () {
    actuarComo(Rol::Mostrador);
    $ventaId = $this->postJson('/api/ventas')->json('id');

    $this->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => unidadDe('RN-4410')->codigo_barras])
        ->assertJsonValidationErrors('metodo_captura');
    $this->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => unidadDe('RN-4410')->codigo_barras, 'metodo_captura' => 'magia'])
        ->assertJsonValidationErrors('metodo_captura');
});

it('RF-12: buscar por código universal devuelve sus referencias con las unidades disponibles para elegir', function () {
    actuarComo(Rol::Mostrador);

    $referencias = collect($this->getJson('/api/buscar?q=IM204')->assertOk()->json('referencias'));

    expect($referencias->pluck('codigo_referencia')->sort()->values()->all())->toBe(['AC-77812', 'DE-IM204-A', 'RN-4410'])
        ->and($referencias->sum(fn ($r) => count($r['unidades_disponibles'])))->toBe(6);
});

it('RF-12: buscar por código de referencia usa el prefijo', function () {
    actuarComo(Rol::Mostrador);

    $this->getJson('/api/buscar?q=F00M')->assertOk()->assertJsonPath('referencias.0.codigo_referencia', 'F00M144136');
});

it('RF-12: buscar por código de barras devuelve la unidad exacta', function () {
    actuarComo(Rol::Mostrador);
    $unidad = unidadDe('RN-4410');

    $this->getJson("/api/buscar?q={$unidad->codigo_barras}")->assertOk()
        ->assertJsonPath('unidad.id', $unidad->id)
        ->assertJsonPath('unidad.referencia.codigo_referencia', 'RN-4410');
});

it('RF-12: las unidades vendidas no aparecen para elegir', function () {
    actuarComo(Rol::Mostrador);
    $ventaId = $this->postJson('/api/ventas')->json('id');
    $this->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => unidadDe('ARR-12V-9D')->codigo_barras, 'metodo_captura' => 'digitado']);

    $this->getJson('/api/buscar?q=ARR-12V')->assertJsonCount(0, 'referencias.0.unidades_disponibles');
});
