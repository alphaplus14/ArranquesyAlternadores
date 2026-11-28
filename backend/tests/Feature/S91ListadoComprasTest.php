<?php

use App\Enums\Rol;
use App\Models\Ingreso;
use App\Models\Proveedor;
use Illuminate\Support\Facades\DB;

/*
 * S-91 (RF-04, RF-05): GET /api/ingresos paginado en el servidor con orden estable (fecha e id
 * descendentes), filtro por mes (YYYY-MM) o rango desde/hasta (el mes manda), combinable con q,
 * borradores y corregidos; GET /api/ingresos/meses cuenta por mes con los demás filtros, sin el mes.
 * Solo consulta: logística y admin 200; mostrador y contable 403.
 */

beforeEach(function () {
    sembrarDemo();
    actuarComo(Rol::Logistica);
});

function s91Proveedor(string $nombre = 'Distribuidora Aurora'): Proveedor
{
    return Proveedor::factory()->create(['nombre' => $nombre]);
}

function s91Documento(Proveedor $p, string $numero, string $fecha, string $estado = 'confirmado'): Ingreso
{
    $i = Ingreso::create(['proveedor_id' => $p->id, 'numero_documento' => $numero, 'tipo' => 'factura', 'fecha' => $fecha, 'usuario_id' => auth()->id()]);
    if ($estado !== 'borrador') {
        DB::table('ingresos')->where('id', $i->id)->update(['estado' => $estado]);
    }

    return $i;
}

function s91Corregir(Ingreso $i): void
{
    DB::table('ingreso_correcciones')->insert([
        'ingreso_id' => $i->id, 'usuario_id' => auth()->id(), 'motivo' => 'prueba S-91',
        'antes' => '{}', 'despues' => '{}', 'total_nuevo' => 0,
    ]);
}

function s91Numeros(array $params): array
{
    return collect(test()->getJson('/api/ingresos?'.http_build_query($params))->assertOk()->json('data'))
        ->pluck('numero_documento')->all();
}

it('S-91 (RF-05): filtra por mes, incluido el último día del mes y el cambio de año', function () {
    $p = s91Proveedor();
    s91Documento($p, 'NOV-30', '2030-11-30');
    s91Documento($p, 'NOV-01', '2030-11-01');
    s91Documento($p, 'DIC-01', '2030-12-01');
    s91Documento($p, 'DIC-31', '2030-12-31');
    s91Documento($p, 'ENE-01', '2031-01-01');
    s91Documento($p, 'OCT-31', '2030-10-31');

    expect(s91Numeros(['mes' => '2030-11']))->toBe(['NOV-30', 'NOV-01'])
        ->and(s91Numeros(['mes' => '2030-12']))->toBe(['DIC-31', 'DIC-01'])
        ->and(s91Numeros(['mes' => '2031-01']))->toBe(['ENE-01'])
        ->and(s91Numeros(['mes' => '2030-09']))->toBe([]);
});

it('S-91: desde y hasta filtran un rango exacto; si llega mes, se ignoran', function () {
    $p = s91Proveedor();
    s91Documento($p, 'A', '2030-11-10');
    s91Documento($p, 'B', '2030-11-20');
    s91Documento($p, 'C', '2030-12-05');

    expect(s91Numeros(['desde' => '2030-11-15', 'hasta' => '2030-12-05']))->toBe(['C', 'B'])
        ->and(s91Numeros(['desde' => '2030-11-10', 'hasta' => '2030-11-10']))->toBe(['A'])
        ->and(s91Numeros(['mes' => '2030-11', 'desde' => '2030-12-01', 'hasta' => '2030-12-31']))->toBe(['B', 'A']);
});

it('S-91: mes se combina con q, borradores y corregidos', function () {
    $aurora = s91Proveedor('Distribuidora Aurora');
    $boreal = s91Proveedor('Repuestos Boreal');
    $a = s91Documento($aurora, 'A-1', '2030-11-05');
    s91Documento($aurora, 'A-2', '2030-11-06', 'borrador');
    s91Documento($boreal, 'B-1', '2030-11-07');
    $viejo = s91Documento($aurora, 'A-0', '2030-10-01');
    s91Corregir($a);
    s91Corregir($viejo);

    expect(s91Numeros(['mes' => '2030-11', 'q' => 'aurora']))->toBe(['A-2', 'A-1'])
        ->and(s91Numeros(['mes' => '2030-11', 'borradores' => 1]))->toBe(['A-2'])
        ->and(s91Numeros(['mes' => '2030-11', 'corregidos' => 1]))->toBe(['A-1'])
        ->and(s91Numeros(['corregidos' => 1]))->toBe(['A-1', 'A-0'])
        ->and(s91Numeros(['mes' => '2030-11', 'q' => 'boreal', 'corregidos' => 1]))->toBe([]);
});

it('S-91: pagina con total y última página correctos, sin repetir ni saltar filas de la misma fecha', function () {
    $p = s91Proveedor();
    foreach (range(1, 7) as $n) {
        s91Documento($p, "MISMA-{$n}", '2030-11-15');
    }
    s91Documento($p, 'NUEVA', '2030-11-20');
    s91Documento($p, 'VIEJA', '2030-11-01');

    $paginas = collect(range(1, 4))->map(fn ($n) => $this->getJson("/api/ingresos?por_pagina=3&page={$n}&proveedor_id={$p->id}")->assertOk());
    expect($paginas[0]->json('total'))->toBe(9)
        ->and($paginas[0]->json('last_page'))->toBe(3)
        ->and($paginas[0]->json('current_page'))->toBe(1)
        ->and($paginas[3]->json('data'))->toBe([]);

    $todas = $paginas->flatMap(fn ($r) => collect($r->json('data'))->pluck('numero_documento'))->all();
    expect($todas)->toHaveCount(9)
        ->and(array_unique($todas))->toHaveCount(9)
        ->and($todas[0])->toBe('NUEVA')
        ->and($todas[8])->toBe('VIEJA')
        // misma fecha: id descendente
        ->and(array_slice($todas, 1, 7))->toBe(['MISMA-7', 'MISMA-6', 'MISMA-5', 'MISMA-4', 'MISMA-3', 'MISMA-2', 'MISMA-1']);
});

it('S-91: por_pagina llega hasta 100 (más se recorta, S-70) y valida mes, fechas y booleanos', function () {
    $this->getJson('/api/ingresos?por_pagina=100')->assertOk()->assertJsonPath('per_page', 100);
    $this->getJson('/api/ingresos?por_pagina=101')->assertOk()->assertJsonPath('per_page', 100); // S-70: se recorta
    $this->getJson('/api/ingresos?mes=2030-13')->assertUnprocessable()->assertJsonValidationErrors('mes');
    $this->getJson('/api/ingresos?mes=noviembre')->assertUnprocessable()->assertJsonValidationErrors('mes');
    $this->getJson('/api/ingresos?desde=2030-11-20&hasta=2030-11-01')->assertUnprocessable()->assertJsonValidationErrors('hasta');
    $this->getJson('/api/ingresos?borradores=quizas')->assertUnprocessable()->assertJsonValidationErrors('borradores');
});

it('S-91: GET /ingresos/meses cuenta por mes, en orden descendente, con los demás filtros y sin el mes', function () {
    $aurora = s91Proveedor('Distribuidora Aurora');
    $boreal = s91Proveedor('Repuestos Boreal');
    s91Documento($aurora, 'A-1', '2030-11-05');
    s91Documento($aurora, 'A-2', '2030-11-30', 'borrador');
    s91Documento($boreal, 'B-1', '2030-11-07');
    s91Documento($aurora, 'A-3', '2030-10-31');
    s91Documento($aurora, 'A-4', '2031-01-01');

    // Solo los años de la prueba: la semilla de demo trae compras propias en otros meses.
    $meses = fn (string $query) => collect($this->getJson('/api/ingresos/meses'.$query)->assertOk()->json('data'))
        ->filter(fn ($m) => str_starts_with($m['mes'], '203'))->values()->all();

    expect($meses(''))->toBe([
        ['mes' => '2031-01', 'total' => 1],
        ['mes' => '2030-11', 'total' => 3],
        ['mes' => '2030-10', 'total' => 1],
    ])->and($meses('?q=aurora&mes=2030-10&desde=2030-10-01&hasta=2030-10-31'))->toBe([
        ['mes' => '2031-01', 'total' => 1],
        ['mes' => '2030-11', 'total' => 2],
        ['mes' => '2030-10', 'total' => 1],
    ])->and($meses('?borradores=1'))->toBe([['mes' => '2030-11', 'total' => 1]]);
});

it('S-91: el listado no hace más consultas con 30 documentos que con 3 (sin N+1)', function () {
    $p = s91Proveedor();
    $consultas = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/ingresos?mes=2030-11&por_pagina=50')->assertOk();

        return count(DB::getQueryLog());
    };
    foreach (range(1, 3) as $n) {
        s91Corregir(s91Documento($p, "P-{$n}", '2030-11-0'.$n));
    }
    $pocas = $consultas();
    foreach (range(4, 30) as $n) {
        s91Documento($p, "P-{$n}", '2030-11-'.str_pad((string) min($n, 28), 2, '0', STR_PAD_LEFT));
    }

    expect($consultas())->toBe($pocas);
});

it('S-91: logística y admin consultan; mostrador y contable reciben 403', function (Rol $rol, int $estado) {
    actuarComo($rol);
    $this->getJson('/api/ingresos?mes=2030-11')->assertStatus($estado);
    $this->getJson('/api/ingresos/meses')->assertStatus($estado);
})->with([
    'logística' => [Rol::Logistica, 200],
    'admin' => [Rol::Admin, 200],
    'mostrador' => [Rol::Mostrador, 403],
    'contable' => [Rol::Contable, 403],
]);
