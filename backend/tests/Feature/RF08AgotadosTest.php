<?php

use App\Enums\Rol;
use App\Models\Categoria;
use App\Models\CodigoUniversal;
use App\Models\Proveedor;
use App\Models\Referencia;
use Illuminate\Support\Facades\DB;

/*
 * RF-08 / S-47: Agotados = referencias ACTIVAS con existencia 0. Por defecto de todos los
 * proveedores, ordenadas por proveedor y código, paginadas en el servidor; proveedor y categoría
 * acotan. Una agotada cuyo universal tiene existencia en otra referencia se marca (regla 2).
 */

function agotados(array $params = [])
{
    return test()->getJson('/api/reportes/agotados?'.http_build_query($params));
}

function codigosAgotados(array $params = []): array
{
    return collect(agotados($params)->assertOk()->json('data'))->pluck('codigo_referencia')->all();
}

/** Referencia del proveedor (y categoría) con $existencia unidades (ingreso confirmado). */
function refAgotados(string $codigo, Proveedor $proveedor, ?Categoria $categoria = null, int $existencia = 0, array $extra = []): Referencia
{
    $ref = Referencia::factory()->create([
        'codigo_referencia' => $codigo,
        'proveedor_id' => $proveedor->id,
        'categoria_id' => ($categoria ?? Categoria::factory()->create())->id,
        ...$extra,
    ]);
    if ($existencia > 0) {
        ingresar($ref, $existencia);
    }

    return $ref->refresh();
}

it('RF-08 / S-47 (a, d): sin proveedor lista las agotadas activas de todos, por proveedor y luego código', function () {
    $zeta = Proveedor::factory()->create(['nombre' => 'Zeta repuestos']);
    $alfa = Proveedor::factory()->create(['nombre' => 'Alfa importaciones']);
    refAgotados('Z-002', $zeta);
    refAgotados('Z-001', $zeta);
    refAgotados('A-900', $alfa);
    refAgotados('A-100', $alfa);
    actuarComo(Rol::Logistica);

    expect(codigosAgotados())->toBe(['A-100', 'A-900', 'Z-001', 'Z-002']);
    expect(agotados()->json('data.0.proveedor'))->toBe(['id' => $alfa->id, 'nombre' => 'Alfa importaciones']);
});

it('RF-08 / S-47 (b): no lista las desactivadas ni las que tienen existencia', function () {
    $prov = Proveedor::factory()->create();
    refAgotados('AGOTADA', $prov);
    refAgotados('CON-STOCK', $prov, existencia: 2);
    refAgotados('INACTIVA', $prov, extra: ['activo' => false]);
    actuarComo(Rol::Logistica);

    expect(codigosAgotados())->toBe(['AGOTADA']);
});

it('RF-08 / S-47 (c): proveedor y categoría acotan, solos o juntos', function () {
    $p1 = Proveedor::factory()->create(['nombre' => 'P1']);
    $p2 = Proveedor::factory()->create(['nombre' => 'P2']);
    $c1 = Categoria::factory()->create();
    $c2 = Categoria::factory()->create();
    refAgotados('P1-C1', $p1, $c1);
    refAgotados('P1-C2', $p1, $c2);
    refAgotados('P2-C1', $p2, $c1);
    actuarComo(Rol::Admin);

    expect(codigosAgotados(['proveedor_id' => $p1->id]))->toBe(['P1-C1', 'P1-C2'])
        ->and(codigosAgotados(['categoria_id' => $c1->id]))->toBe(['P1-C1', 'P2-C1'])
        ->and(codigosAgotados(['proveedor_id' => $p1->id, 'categoria_id' => $c1->id]))->toBe(['P1-C1'])
        ->and(codigosAgotados(['proveedor_id' => $p2->id, 'categoria_id' => $c2->id]))->toBe([]);
    agotados(['proveedor_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('proveedor_id');
});

it('RF-08 / S-47 (e): paginación en el servidor, 50 por defecto y máximo 200', function () {
    $prov = Proveedor::factory()->create();
    $cat = Categoria::factory()->create();
    Referencia::factory()->count(210)->sequence(fn ($s) => ['codigo_referencia' => sprintf('PAG-%03d', $s->index)])
        ->create(['proveedor_id' => $prov->id, 'categoria_id' => $cat->id]);
    actuarComo(Rol::Logistica);

    $r = agotados()->assertOk();
    expect($r->json('meta.total'))->toBe(210)->and($r->json('data'))->toHaveCount(50)->and($r->json('meta.per_page'))->toBe(50);
    expect(agotados(['por_pagina' => 500])->json('data'))->toHaveCount(200);
    expect(agotados(['por_pagina' => 200, 'page' => 2])->json('data'))->toHaveCount(10)
        ->and(agotados(['por_pagina' => 200, 'page' => 2])->json('data.0.codigo_referencia'))->toBe('PAG-200');
});

it('RF-08 / S-47 (f): universal_existencia suma las demás referencias activas del universal; sin universal, null', function () {
    $prov = Proveedor::factory()->create();
    $cat = Categoria::factory()->create(['admite_codigo_universal' => true]);
    $universal = CodigoUniversal::factory()->create(['codigo' => 'IM-S47']);
    $vacio = CodigoUniversal::factory()->create(['codigo' => 'IM-VACIO']);
    refAgotados('U-AGOTADA', $prov, $cat, extra: ['codigo_universal_id' => $universal->id]);
    refAgotados('U-CON-3', $prov, $cat, 3, ['codigo_universal_id' => $universal->id]);
    refAgotados('U-CON-2', $prov, $cat, 2, ['codigo_universal_id' => $universal->id]);
    // Una inactiva con existencia no cuenta para el universal (S-26).
    $inactiva = refAgotados('U-INACTIVA', $prov, $cat, 4, ['codigo_universal_id' => $universal->id]);
    DB::table('referencias')->where('id', $inactiva->id)->update(['activo' => false]);
    refAgotados('V-AGOTADA', $prov, $cat, extra: ['codigo_universal_id' => $vacio->id]);
    refAgotados('SUELTA', $prov);
    actuarComo(Rol::Logistica);

    $filas = collect(agotados()->assertOk()->json('data'))->keyBy('codigo_referencia');
    expect($filas->keys()->all())->toBe(['SUELTA', 'U-AGOTADA', 'V-AGOTADA'])
        ->and($filas['U-AGOTADA']['universal_existencia'])->toBe(5)
        ->and($filas['U-AGOTADA']['codigo_universal']['codigo'])->toBe('IM-S47')
        ->and($filas['V-AGOTADA']['universal_existencia'])->toBe(0)
        ->and($filas['SUELTA']['universal_existencia'])->toBeNull();
});

it('RF-08 / S-47 (g): consultas acotadas con 100 agotadas (sin N+1)', function () {
    $cat = Categoria::factory()->create(['admite_codigo_universal' => true]);
    $universales = CodigoUniversal::factory()->count(5)->create();
    $proveedores = Proveedor::factory()->count(5)->create();
    $contar = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        agotados(['por_pagina' => 200])->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $crear = fn (int $n, int $desde) => Referencia::factory()->count($n)->sequence(fn ($s) => [
        'codigo_referencia' => sprintf('NQ-%03d', $desde + $s->index),
        'proveedor_id' => $proveedores[$s->index % 5]->id,
        'codigo_universal_id' => $s->index % 2 ? $universales[$s->index % 5]->id : null,
    ])->create(['categoria_id' => $cat->id]);
    actuarComo(Rol::Logistica);

    $crear(10, 0);
    $con10 = $contar();
    $crear(90, 10);
    $con100 = $contar();

    expect(agotados(['por_pagina' => 200])->json('meta.total'))->toBe(100)
        ->and($con100)->toBe($con10)
        ->and($con100)->toBeLessThanOrEqual(12);
});

it('RF-08 / S-47 (h): mostrador recibe 403; admin y logística 200', function () {
    actuarComo(Rol::Mostrador);
    agotados()->assertForbidden();
    actuarComo(Rol::Admin);
    agotados()->assertOk();
    actuarComo(Rol::Logistica);
    agotados()->assertOk();
});

it('RF-08: una referencia que se agota por venta aparece en el reporte', function () {
    sembrarDemo();
    actuarComo(Rol::Mostrador);
    $ventaId = abrirVenta();
    $this->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => unidadDe('ARR-12V-9D')->codigo_barras, 'metodo_captura' => 'escaneado'])->assertCreated();

    // S-28: el reporte es de logística y admin.
    actuarComo(Rol::Logistica);

    expect(codigosAgotados(['proveedor_id' => referencia('ARR-12V-9D')->proveedor_id]))->toContain('ARR-12V-9D', 'SEN-TEMP-01')
        ->and(codigosAgotados())->toContain('ARR-12V-9D', 'SEN-TEMP-01');
});
