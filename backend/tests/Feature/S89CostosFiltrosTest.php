<?php

use App\Enums\Rol;
use App\Models\Categoria;
use App\Models\CodigoUniversal;
use App\Models\Referencia;
use App\Models\User;
use App\Services\IngresoService;
use Illuminate\Support\Facades\DB;

/*
 * S-89 (presentación de S-71/S-80): `opciones_filtro` del historial de un producto, sin aplicar
 * los filtros: compras confirmadas y proveedores distintos. Con eso la pantalla oculta los
 * filtros que no tienen nada que acotar.
 */

beforeEach(fn () => sembrarDemo());

function S89Ref(): Referencia
{
    return Referencia::factory()->create(['precio_venta' => 50000]);
}

function S89Ingreso(Referencia $ref, string $fecha, array $lineas, bool $confirmar = true): void
{
    $usuario = User::factory()->rol(Rol::Logistica)->create();
    $servicio = app(IngresoService::class);
    $ingreso = $servicio->crearBorrador(['proveedor_id' => $ref->proveedor_id, 'numero_documento' => 'S89-'.random_int(100000, 999999), 'fecha' => $fecha], $usuario);
    foreach ($lineas as $l) {
        $servicio->agregarItem($ingreso, ['referencia_id' => $ref->id, 'iva_porcentaje' => 0, 'descuento_porcentaje' => 0, ...$l]);
    }
    if ($confirmar) {
        $servicio->confirmar($ingreso, $usuario);
    }
}

function S89Historial(array $params)
{
    return test()->getJson('/api/costos?'.http_build_query($params));
}

it('S-89: una referencia con una sola compra trae 1 compra y su único proveedor', function () {
    actuarComo(Rol::Logistica);
    $ref = S89Ref();
    S89Ingreso($ref, '2026-11-01', [['cantidad' => 1, 'costo_unitario' => 100000]]);

    $r = S89Historial(['referencia_id' => $ref->id])->assertOk();

    expect($r->json('opciones_filtro'))->toBe([
        'compras' => 1,
        'proveedores' => [['id' => $ref->proveedor_id, 'nombre' => $ref->proveedor->nombre]],
    ]);
});

it('S-89: las opciones no dependen de los filtros ni de la página, y los borradores no cuentan', function () {
    actuarComo(Rol::Logistica);
    $ref = S89Ref();
    S89Ingreso($ref, '2026-10-01', [['cantidad' => 1, 'costo_unitario' => 100000]]);
    S89Ingreso($ref, '2026-11-01', [['cantidad' => 1, 'costo_unitario' => 110000]]);
    S89Ingreso($ref, '2026-11-05', [['cantidad' => 1, 'costo_unitario' => 120000]], confirmar: false);

    $r = S89Historial(['referencia_id' => $ref->id, 'desde' => '2026-10-15', 'por_pagina' => 1])->assertOk();

    expect($r->json('resumen.compras'))->toBe(1) // el resumen sí va filtrado
        ->and($r->json('opciones_filtro.compras'))->toBe(2)
        ->and($r->json('opciones_filtro.proveedores'))->toHaveCount(1);
});

it('S-89: un universal lista solo los proveedores de sus compras, ordenados y en un número fijo de consultas', function () {
    actuarComo(Rol::Logistica);
    $universal = CodigoUniversal::factory()->create();
    $categoria = Categoria::factory()->create(['admite_codigo_universal' => true]);
    $a = S89Ref();
    $b = S89Ref();
    $sinCompras = S89Ref();
    foreach ([$a, $b, $sinCompras] as $ref) {
        $ref->update(['codigo_universal_id' => $universal->id, 'categoria_id' => $categoria->id]);
    }
    S89Ingreso($a, '2026-10-01', [['cantidad' => 1, 'costo_unitario' => 100000]]);
    S89Ingreso($a, '2026-10-05', [['cantidad' => 1, 'costo_unitario' => 100000]]);
    S89Ingreso($b, '2026-11-01', [['cantidad' => 1, 'costo_unitario' => 90000]]);

    DB::enableQueryLog();
    $r = S89Historial(['codigo_universal_id' => $universal->id, 'proveedor_id' => $a->proveedor_id])->assertOk();
    $consultas = count(DB::getQueryLog());
    DB::disableQueryLog();

    $esperados = collect([$a, $b])->map(fn ($x) => ['id' => $x->proveedor_id, 'nombre' => $x->proveedor->nombre])
        ->sortBy('nombre', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    expect($r->json('opciones_filtro.compras'))->toBe(3)
        ->and($r->json('opciones_filtro.proveedores'))->toBe($esperados)
        ->and($r->json('total'))->toBe(2); // el filtro de proveedor sí acota las filas
    expect($consultas)->toBeLessThan(25);
});

it('S-89: la lista general no trae opciones_filtro', function () {
    actuarComo(Rol::Admin);

    expect(S89Historial([])->assertOk()->json('opciones_filtro'))->toBeNull();
});
