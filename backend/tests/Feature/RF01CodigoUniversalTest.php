<?php

use App\Enums\Rol;
use App\Models\Categoria;
use App\Models\CodigoUniversal;
use App\Models\Marca;
use App\Models\Proveedor;

beforeEach(fn () => sembrarDemo());

it('RF-01: el universal IM204 con referencias de 2, 2 y 2 muestra 6 y el desglose de cada una', function () {
    actuarComo(Rol::Admin);
    $im204 = CodigoUniversal::where('codigo', 'IM204')->first();

    $respuesta = $this->getJson("/api/codigos-universales/{$im204->id}")->assertOk();

    expect($respuesta->json('existencia_total'))->toBe(6)
        ->and($respuesta->json('referencias'))->toHaveCount(3)
        ->and(collect($respuesta->json('referencias'))->pluck('existencia')->all())->toBe([2, 2, 2]);
});

it('RF-01: una referencia de una categoría que no admite universal no puede tener codigo_universal_id', function () {
    actuarComo(Rol::Admin);
    $arranques = Categoria::where('nombre', 'Arranques')->first();
    $im204 = CodigoUniversal::where('codigo', 'IM204')->first();

    $this->postJson('/api/referencias', [
        'codigo_referencia' => 'ARR-X1',
        'marca_id' => Marca::first()->id,
        'proveedor_id' => Proveedor::first()->id,
        'categoria_id' => $arranques->id,
        'codigo_universal_id' => $im204->id,
        'descripcion' => 'Arranque forzado a universal',
        'precio_venta' => 1000,
    ])->assertUnprocessable()->assertJsonValidationErrors('codigo_universal_id');
});

it('RF-01: cambiar la categoría a una que no admite universal se rechaza si la referencia ya tiene uno', function () {
    actuarComo(Rol::Admin);

    $this->patchJson('/api/referencias/'.referencia('RN-4410')->id, [
        'categoria_id' => Categoria::where('nombre', 'Sensores')->value('id'),
    ])->assertUnprocessable()->assertJsonValidationErrors('codigo_universal_id');
});

it('RF-01: el universal debe ser de la misma categoría que la referencia', function () {
    actuarComo(Rol::Admin);
    $poleas = Categoria::where('nombre', 'Poleas')->first();

    $this->postJson('/api/referencias', [
        'codigo_referencia' => 'POL-1',
        'marca_id' => Marca::first()->id,
        'proveedor_id' => Proveedor::first()->id,
        'categoria_id' => $poleas->id,
        'codigo_universal_id' => CodigoUniversal::where('codigo', 'IM204')->value('id'), // es de reguladores
        'descripcion' => 'Polea',
        'precio_venta' => 1000,
    ])->assertUnprocessable()->assertJsonValidationErrors('codigo_universal_id');
});

it('RF-01: una pieza fuera del esquema universal se consulta por su referencia original', function () {
    actuarComo(Rol::Mostrador);

    $respuesta = $this->getJson('/api/buscar?q=ARR-12V-9D')->assertOk();

    expect($respuesta->json('referencias.0.codigo_referencia'))->toBe('ARR-12V-9D')
        ->and($respuesta->json('referencias.0.codigo_universal'))->toBeNull()
        ->and($respuesta->json('referencias.0.unidades_disponibles'))->toHaveCount(1);
});

it('RF-01: no se crea un universal en una categoría que no lo admite', function () {
    actuarComo(Rol::Admin);

    $this->postJson('/api/codigos-universales', [
        'codigo' => 'SEN-U1',
        'categoria_id' => Categoria::where('nombre', 'Sensores')->value('id'),
    ])->assertUnprocessable()->assertJsonValidationErrors('categoria_id');
});
