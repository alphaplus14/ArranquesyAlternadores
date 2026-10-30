<?php

use App\Enums\Rol;
use App\Models\Proveedor;
use App\Models\Referencia;

beforeEach(fn () => sembrarDemo());

it('RF-08: los agotados de un proveedor solo listan sus referencias con existencia 0', function () {
    actuarComo(Rol::Logistica);
    $proveedor = referencia('SEN-TEMP-01')->proveedor_id;

    // El mismo proveedor tiene un arranque con existencia 1: no es agotado.
    $arranque = referencia('ARR-12V-9D');
    expect($arranque->proveedor_id)->toBe($proveedor);

    // Una referencia en 0 de otro proveedor no debe aparecer.
    Referencia::factory()->create(['proveedor_id' => Proveedor::factory()->create()->id]);

    $codigos = collect($this->getJson("/api/reportes/agotados?proveedor_id={$proveedor}")->assertOk()->json('data'))
        ->pluck('codigo_referencia');

    expect($codigos->all())->toBe(['SEN-TEMP-01']);
});

it('RF-08: una referencia que se agota por venta aparece en el reporte', function () {
    actuarComo(Rol::Mostrador);
    $arranque = referencia('ARR-12V-9D');
    $ventaId = $this->postJson('/api/ventas')->json('id');
    $this->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => unidadDe('ARR-12V-9D')->codigo_barras, 'metodo_captura' => 'escaneado']);

    $codigos = collect($this->getJson("/api/reportes/agotados?proveedor_id={$arranque->proveedor_id}")->json('data'))
        ->pluck('codigo_referencia');

    expect($codigos)->toContain('ARR-12V-9D', 'SEN-TEMP-01');
});

it('RF-08: el proveedor es obligatorio', function () {
    actuarComo(Rol::Mostrador);

    $this->getJson('/api/reportes/agotados')->assertUnprocessable();
});
