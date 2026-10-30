<?php

use App\Enums\Rol;
use App\Models\CodigoUniversal;

beforeEach(fn () => sembrarDemo());

it('RF-02: si una referencia pasa de 2 a 3, el universal pasa de 6 a 7 y el detalle identifica cuál cambió', function () {
    actuarComo(Rol::Admin);
    $im204 = CodigoUniversal::where('codigo', 'IM204')->first();
    $cambiada = referencia('DE-IM204-A');

    ingresar($cambiada, 1);

    $respuesta = $this->getJson("/api/codigos-universales/{$im204->id}")->assertOk();
    $detalle = collect($respuesta->json('referencias'))->keyBy('codigo_referencia');

    expect($respuesta->json('existencia_total'))->toBe(7)
        ->and($detalle['DE-IM204-A']['existencia'])->toBe(3)
        ->and($detalle['RN-4410']['existencia'])->toBe(2)
        ->and($detalle['AC-77812']['existencia'])->toBe(2);
});

it('RF-02: el desglose por marca conserva la existencia de cada marca', function () {
    actuarComo(Rol::Admin);
    $im210 = CodigoUniversal::where('codigo', 'IM210')->first();

    $porMarca = collect($this->getJson("/api/codigos-universales/{$im210->id}")->json('por_marca'))
        ->pluck('existencia', 'marca');

    expect($porMarca->all())->toEqual(['Bosch' => 1, 'Transpo' => 1, 'Marca Ejemplo' => 1]);
});

it('RF-02: el listado de universales trae el total calculado, no guardado', function () {
    actuarComo(Rol::Admin);

    $fila = collect($this->getJson('/api/codigos-universales?q=IM204')->json('data'))->firstWhere('codigo', 'IM204');

    expect((int) $fila['existencia'])->toBe(6);
});
