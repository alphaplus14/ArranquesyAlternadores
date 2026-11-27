<?php

use App\Enums\Rol;
use App\Models\Categoria;
use App\Models\CodigoUniversal;
use App\Models\Proveedor;
use App\Models\Referencia;
use Illuminate\Support\Facades\DB;

/*
 * S-82 (RF-01, RF-02, RF-11, RF-22; reglas 2 y 12; ajusta S-59): Inventario lista universales (fila
 * agrupadora con sus referencias) y referencias sin universal. Una referencia con universal nunca
 * sale suelta. Demo: IM204 = RN-4410 + DE-IM204-A + AC-77812, 2 + 2 + 2 = 6.
 */

beforeEach(fn () => sembrarDemo());

function inventario(array $params = [])
{
    return test()->getJson('/api/inventario?'.http_build_query(['por_pagina' => 100, ...$params]));
}

/** Ítem de la lista por tipo y código. */
function itemInventario(array $datos, string $tipo, string $codigo): ?array
{
    return collect($datos)->first(fn ($i) => $i['tipo'] === $tipo && ($tipo === 'universal' ? $i['codigo'] : $i['codigo_referencia']) === $codigo);
}

it('S-82: una referencia con universal no sale suelta; el universal suma sus referencias (RF-02)', function () {
    actuarComo(Rol::Logistica);
    $datos = inventario()->assertOk()->json('data');

    $im204 = itemInventario($datos, 'universal', 'IM204');
    expect($im204)->not->toBeNull()
        ->and($im204['existencia_total'])->toBe(6)
        ->and($im204['cantidad_referencias'])->toBe(3)
        ->and(collect($im204['referencias'])->pluck('codigo_referencia')->all())->toBe(['AC-77812', 'DE-IM204-A', 'RN-4410'])
        ->and(collect($im204['proveedores']))->toHaveCount(3)
        ->and(itemInventario($datos, 'referencia', 'RN-4410'))->toBeNull()
        ->and(itemInventario($datos, 'referencia', 'ARR-12V-9D'))->not->toBeNull();

    $sueltasConUniversal = collect($datos)->where('tipo', 'referencia')->whereNotNull('codigo_universal_id');
    expect($sueltasConUniversal)->toBeEmpty();
});

it('S-82: buscar el código de una referencia hija trae su universal con las coincidencias; buscar el universal trae el grupo', function () {
    actuarComo(Rol::Mostrador);
    $rn = referencia('RN-4410');

    $porHija = inventario(['q' => 'RN-4410'])->assertOk()->json('data');
    expect($porHija)->toHaveCount(1)
        ->and($porHija[0]['tipo'])->toBe('universal')
        ->and($porHija[0]['codigo'])->toBe('IM204')
        ->and($porHija[0]['coincidencias'])->toBe([$rn->id])
        ->and($porHija[0]['referencias'])->toHaveCount(3);

    $porUniversal = inventario(['q' => 'IM204'])->assertOk()->json('data');
    $grupo = itemInventario($porUniversal, 'universal', 'IM204');
    expect($grupo['referencias'])->toHaveCount(3)
        // Las hijas siguen dentro del grupo, nunca sueltas.
        ->and(collect($porUniversal)->where('tipo', 'referencia')->pluck('codigo_referencia')->all())->not->toContain('RN-4410');
});

it('S-82: el filtro por proveedor trae el universal con solo sus referencias de ese proveedor; "tipo" separa grupos y sueltas', function () {
    actuarComo(Rol::Admin);
    $rn = referencia('RN-4410');

    $datos = inventario(['proveedor_id' => $rn->proveedor_id])->assertOk()->json('data');
    $im204 = itemInventario($datos, 'universal', 'IM204');
    expect(collect($im204['referencias'])->pluck('codigo_referencia')->all())->toBe(['RN-4410'])
        ->and($im204['existencia_total'])->toBe(6) // la existencia del universal sigue siendo la suma de todas
        ->and(collect($datos)->where('tipo', 'referencia')->every(fn ($r) => $r['proveedor_id'] === $rn->proveedor_id))->toBeTrue();

    expect(collect(inventario(['tipo' => 'universales'])->json('data'))->pluck('tipo')->unique()->all())->toBe(['universal'])
        ->and(collect(inventario(['tipo' => 'sin_universal'])->json('data'))->pluck('tipo')->unique()->all())->toBe(['referencia']);

    $uno = inventario(['universal_id' => $rn->codigo_universal_id])->json('data');
    expect($uno)->toHaveCount(1)->and($uno[0]['codigo'])->toBe('IM204');
});

it('S-82 / S-28 / S-71: mostrador ve los universales sin mínimos ni costos; logística sí', function () {
    actuarComo(Rol::Mostrador);
    $im204 = itemInventario(inventario()->json('data'), 'universal', 'IM204');
    expect($im204)->not->toHaveKeys(['stock_minimo', 'minimo_efectivo', 'bajo_minimo'])
        ->and($im204['referencias'][0])->not->toHaveKeys(['stock_minimo', 'bajo_minimo']);

    actuarComo(Rol::Logistica);
    $im204 = itemInventario(inventario()->json('data'), 'universal', 'IM204');
    expect($im204)->toHaveKeys(['stock_minimo', 'minimo_efectivo', 'bajo_minimo'])
        ->and($im204['referencias'][0])->toHaveKey('ultimo_costo');
});

it('S-82 / RF-22: el bajo mínimo del universal se evalúa sobre la suma de sus activas', function () {
    CodigoUniversal::where('codigo', 'IM204')->update(['stock_minimo' => 6]);
    actuarComo(Rol::Admin);

    $im204 = itemInventario(inventario()->json('data'), 'universal', 'IM204');
    expect($im204['bajo_minimo'])->toBeTrue()->and($im204['minimo_efectivo'])->toBe(6)
        ->and(collect($im204['referencias'])->every(fn ($r) => $r['bajo_minimo'] === true))->toBeTrue();
});

it('S-82: un universal sin referencias sale (para poder llenarlo); con filtro de proveedor no', function () {
    $cat = Categoria::where('admite_codigo_universal', true)->firstOrFail();
    CodigoUniversal::create(['codigo' => 'ZZ-VACIO', 'categoria_id' => $cat->id]);
    actuarComo(Rol::Logistica);

    expect(itemInventario(inventario(['q' => 'ZZ-VACIO'])->json('data'), 'universal', 'ZZ-VACIO'))->not->toBeNull()
        ->and(itemInventario(inventario(['q' => 'ZZ-VACIO', 'proveedor_id' => Proveedor::value('id')])->json('data'), 'universal', 'ZZ-VACIO'))->toBeNull();
});

it('S-82: la paginación es estable: sin duplicados ni huecos entre páginas, orden por código', function () {
    $cat = Categoria::where('admite_codigo_universal', false)->firstOrFail();
    $proveedor = Proveedor::firstOrFail();
    foreach (range(1, 12) as $n) {
        Referencia::factory()->create(['codigo_referencia' => sprintf('PAG-%02d', $n), 'proveedor_id' => $proveedor->id, 'categoria_id' => $cat->id]);
    }
    actuarComo(Rol::Logistica);

    $total = inventario()->json('total');
    $vistos = [];
    $paginas = (int) ceil($total / 5);
    foreach (range(1, $paginas) as $p) {
        foreach (inventario(['por_pagina' => 5, 'page' => $p])->json('data') as $i) {
            $vistos[] = $i['tipo'].':'.$i['id'];
        }
    }
    $todos = collect(inventario()->json('data'));
    expect($vistos)->toHaveCount($total)
        ->and(array_unique($vistos))->toHaveCount($total)
        ->and($vistos)->toBe($todos->map(fn ($i) => $i['tipo'].':'.$i['id'])->all());
});

it('S-82: número fijo de consultas, sin importar cuántos universales y referencias haya en la página', function () {
    actuarComo(Rol::Logistica);
    $contar = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        inventario()->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $antes = $contar();

    $cat = Categoria::where('admite_codigo_universal', true)->firstOrFail();
    $proveedor = Proveedor::firstOrFail();
    foreach (range(1, 5) as $n) {
        $u = CodigoUniversal::create(['codigo' => "QC-{$n}", 'categoria_id' => $cat->id]);
        foreach (range(1, 3) as $m) {
            Referencia::factory()->create(['codigo_referencia' => "QC-{$n}-{$m}", 'proveedor_id' => $proveedor->id, 'categoria_id' => $cat->id, 'codigo_universal_id' => $u->id]);
        }
    }

    expect($contar())->toBe($antes);
});

it('S-82 / S-59: borrar un universal con referencias responde 422 (no 500); vacío, el admin lo borra', function () {
    $cat = Categoria::where('admite_codigo_universal', true)->firstOrFail();
    $vacio = CodigoUniversal::create(['codigo' => 'ZZ-BORRAR', 'categoria_id' => $cat->id]);
    $im204 = CodigoUniversal::where('codigo', 'IM204')->firstOrFail();

    actuarComo(Rol::Logistica);
    $this->deleteJson("/api/codigos-universales/{$vacio->id}")->assertForbidden();

    actuarComo(Rol::Admin);
    $this->deleteJson("/api/codigos-universales/{$im204->id}")->assertUnprocessable();
    $this->deleteJson("/api/codigos-universales/{$vacio->id}")->assertNoContent();
});
