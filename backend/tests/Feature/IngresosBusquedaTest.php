<?php

use App\Enums\Rol;
use App\Models\Ingreso;
use App\Models\Proveedor;
use Illuminate\Support\Facades\DB;

/*
 * R-F (RF-04, RF-05, RF-20): GET /api/ingresos?q= (2 a 60 caracteres) busca por número de documento
 * (prefijo), nombre del proveedor (contiene) o NIT normalizado (con o sin puntos y guion); se combina con
 * proveedor_id, estado y corregidos. Solo admin y logística; sin N+1.
 */

beforeEach(function () {
    sembrarDemo();
    actuarComo(Rol::Logistica);
});

function proveedorBusqueda(string $nombre, string $nit): Proveedor
{
    return Proveedor::factory()->create(['nombre' => $nombre, 'nit' => $nit]);
}

function documentoBusqueda(Proveedor $proveedor, string $numero, string $estado = 'borrador'): Ingreso
{
    $ingreso = Ingreso::create(['proveedor_id' => $proveedor->id, 'numero_documento' => $numero, 'tipo' => 'factura', 'fecha' => '2026-11-21', 'usuario_id' => auth()->id()]);
    if ($estado !== 'borrador') {
        DB::table('ingresos')->where('id', $ingreso->id)->update(['estado' => $estado]);
    }

    return $ingreso;
}

function numerosBusqueda(array $params): array
{
    return collect(test()->getJson('/api/ingresos?'.http_build_query($params))->assertOk()->json('data'))
        ->pluck('numero_documento')->sort()->values()->all();
}

it('R-F: busca por número de documento por prefijo', function () {
    $p = proveedorBusqueda('Distribuidora Aurora', '900.111.222-3');
    documentoBusqueda($p, 'FEV-1001');
    documentoBusqueda($p, 'FEV-1002');
    documentoBusqueda($p, 'REM-77');

    expect(numerosBusqueda(['q' => 'FEV-10']))->toBe(['FEV-1001', 'FEV-1002'])
        ->and(numerosBusqueda(['q' => 'fev-1002']))->toBe(['FEV-1002'])
        ->and(numerosBusqueda(['q' => '1001']))->toBe([]) // prefijo, no "contiene"
        ->and(numerosBusqueda(['q' => 'ZZ-99']))->toBe([]);
});

it('R-F: busca por nombre del proveedor (contiene)', function () {
    $aurora = proveedorBusqueda('Distribuidora Aurora', '900.111.222-3');
    $boreal = proveedorBusqueda('Repuestos Boreal', '800.333.444-5');
    documentoBusqueda($aurora, 'A-1');
    documentoBusqueda($boreal, 'B-1');

    expect(numerosBusqueda(['q' => 'aurora']))->toBe(['A-1'])
        ->and(numerosBusqueda(['q' => 'tribuidora Au']))->toBe(['A-1'])
        ->and(numerosBusqueda(['q' => 'respuestos']))->toBe([]);
});

it('R-F: busca por NIT con o sin puntos y guion', function () {
    $aurora = proveedorBusqueda('Distribuidora Aurora', '900.111.222-3');
    $boreal = proveedorBusqueda('Repuestos Boreal', '800333444-5');
    documentoBusqueda($aurora, 'A-1');
    documentoBusqueda($boreal, 'B-1');

    expect(numerosBusqueda(['q' => '900.111.222-3']))->toBe(['A-1'])
        ->and(numerosBusqueda(['q' => '9001112223']))->toBe(['A-1'])
        ->and(numerosBusqueda(['q' => '900111222-3']))->toBe(['A-1'])
        ->and(numerosBusqueda(['q' => '800.333.444-5']))->toBe(['B-1'])
        ->and(numerosBusqueda(['q' => '8003334445']))->toBe(['B-1']);
});

it('R-F: q se combina con estado, proveedor_id y corregidos', function () {
    $aurora = proveedorBusqueda('Distribuidora Aurora', '900.111.222-3');
    $boreal = proveedorBusqueda('Aurora Boreal', '800.333.444-5');
    documentoBusqueda($aurora, 'F-1', 'borrador');
    documentoBusqueda($aurora, 'F-2', 'confirmado');
    documentoBusqueda($boreal, 'F-3', 'confirmado');

    expect(numerosBusqueda(['q' => 'aurora']))->toBe(['F-1', 'F-2', 'F-3'])
        ->and(numerosBusqueda(['q' => 'aurora', 'estado' => 'confirmado']))->toBe(['F-2', 'F-3'])
        ->and(numerosBusqueda(['q' => 'aurora', 'estado' => 'confirmado', 'proveedor_id' => $aurora->id]))->toBe(['F-2'])
        ->and(numerosBusqueda(['q' => 'F-', 'estado' => 'borrador']))->toBe(['F-1'])
        ->and(numerosBusqueda(['q' => 'aurora', 'corregidos' => 1]))->toBe([]);
});

it('R-F: q de menos de 2 o más de 60 caracteres responde 422', function () {
    $this->getJson('/api/ingresos?q=a')->assertUnprocessable()->assertJsonValidationErrors('q');
    $this->getJson('/api/ingresos?q='.str_repeat('x', 61))->assertUnprocessable()->assertJsonValidationErrors('q');
    $this->getJson('/api/ingresos?q='.str_repeat('x', 60))->assertOk();
});

it('R-F: el mostrador recibe 403 al listar ingresos con q', function () {
    actuarComo(Rol::Mostrador);

    $this->getJson('/api/ingresos?q=FEV')->assertForbidden();
});

it('R-F: la búsqueda no hace consultas por fila (sin N+1)', function () {
    $contar = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->getJson('/api/ingresos?'.http_build_query(['q' => 'Aurora', 'por_pagina' => 100]))->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $proveedores = collect(range(1, 5))->map(fn ($n) => proveedorBusqueda("Aurora {$n}", "90{$n}.000.000-1"));
    foreach (range(1, 5) as $n) {
        documentoBusqueda($proveedores[$n - 1], "N-{$n}");
    }
    $con5 = $contar();

    foreach (range(6, 60) as $n) {
        documentoBusqueda($proveedores[$n % 5], "N-{$n}");
    }
    $con60 = $contar();

    expect(test()->getJson('/api/ingresos?q=Aurora&por_pagina=100')->json('total'))->toBe(60)
        ->and($con60)->toBe($con5);
});
