<?php

use App\Enums\Rol;
use App\Models\Proveedor;
use App\Models\Referencia;

/*
 * S-83 (amplía S-29; RF-08): el mostrador no recibe el NIT ni el asesor del proveedor. Admin,
 * logística y contable los siguen recibiendo. El mostrador sigue filtrando Inventario por proveedor.
 */

beforeEach(function () {
    sembrarDemo();
    Proveedor::query()->orderBy('id')->first()->update(['nit' => '12345678', 'asesor' => 'Mario']);
});

it('S-83: el mostrador recibe los proveedores sin nit ni asesor', function () {
    actuarComo(Rol::Mostrador);

    $lista = $this->getJson('/api/proveedores')->assertOk()->json();

    expect($lista)->not->toBeEmpty();
    foreach ($lista as $p) {
        expect(array_keys($p))->toEqualCanonicalizing(['id', 'nombre', 'plazo_dias']);
    }
});

it('S-83: admin, logística y contable siguen recibiendo nit y asesor', function (Rol $rol) {
    actuarComo($rol);

    $p = collect($this->getJson('/api/proveedores')->assertOk()->json())->firstWhere('nit', '12345678');

    expect($p)->not->toBeNull()
        ->and($p['asesor'])->toBe('Mario')
        ->and(array_keys($p))->toEqualCanonicalizing(['id', 'nombre', 'nit', 'asesor', 'plazo_dias']);
})->with([
    'admin' => Rol::Admin,
    'logística' => Rol::Logistica,
    'contable' => Rol::Contable,
]);

it('S-83: el mostrador filtra Inventario por proveedor y la respuesta no trae nit ni asesor', function () {
    actuarComo(Rol::Mostrador);
    $proveedor = Proveedor::query()->whereHas('referencias')->orderBy('id')->firstOrFail();
    $proveedor->update(['nit' => '87654321', 'asesor' => 'Mario']);
    $esperadas = Referencia::where('proveedor_id', $proveedor->id)->count();

    $referencias = $this->getJson("/api/referencias?proveedor_id={$proveedor->id}&por_pagina=100")->assertOk();
    $ids = collect($referencias->json('data'))->pluck('proveedor.id')->unique()->values()->all();
    expect($ids)->toBe([$proveedor->id])
        ->and($referencias->getContent())->not->toContain('"nit"')->not->toContain('"asesor"')->not->toContain('87654321');

    $inventario = $this->getJson("/api/inventario?proveedor_id={$proveedor->id}&por_pagina=100")->assertOk();
    expect($inventario->getContent())->not->toContain('"nit"')->not->toContain('"asesor"')->not->toContain('87654321')
        ->and($esperadas)->toBeGreaterThan(0);
});
