<?php

use App\Enums\Rol;
use App\Models\AbonoAnulacion;
use App\Models\AbonoCliente;
use App\Models\Cliente;
use App\Models\CuentaPago;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * S-97 (RF-17, RF-18, RF-29; abonos de RF-15): GET /api/reportes/cartera-recuperada, solo consulta.
 * Abonos de clientes por la fecha del abono, en detalle o por cliente; totales de todo lo filtrado
 * (recuperado y cantidad de vigentes, clientes, anulado aparte y por cuenta) que no dependen de la
 * vista ni de la página. No incluye los recaudos de Contapyme (S-76).
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $this->contable = actuarComo(Rol::Contable);
    $this->mostrador = User::factory()->rol(Rol::Mostrador)->create();
});

afterEach(fn () => Carbon::setTestNow());

/** Venta a crédito de solo valor (S-73) registrada por el mostrador; devuelve su id. */
function s97Venta(Cliente $cliente, int $valor, string $tipo = 'factura', ?string $numero = null): int
{
    $anterior = auth()->user();
    actuarComo(Rol::Mostrador);
    $id = test()->postJson('/api/ventas/credito', ['cliente_id' => $cliente->id, 'tipo_documento' => $tipo, 'valor_total' => $valor, 'numero_documento' => $numero])
        ->assertCreated()->json('id');
    test()->actingAs($anterior);

    return $id;
}

/** Abono escrito directo (fecha, cuenta y documento controlados). */
function s97Abono(Cliente $cliente, int $valor, string $fecha, ?int $ventaId = null, ?CuentaPago $cuenta = null, array $extra = []): AbonoCliente
{
    $cuenta ??= cuentaEfectivo();

    return AbonoCliente::create([
        'cliente_id' => $cliente->id, 'venta_id' => $ventaId, 'fecha' => $fecha, 'valor' => $valor,
        'medio' => $cuenta->tipo->medio(), 'cuenta_pago_id' => $cuenta->id, 'usuario_id' => test()->mostrador->id, ...$extra,
    ]);
}

function s97Anular(AbonoCliente $abono, string $motivo = 'Abono duplicado'): void
{
    AbonoAnulacion::create(['abono_id' => $abono->id, 'motivo' => $motivo, 'usuario_id' => User::factory()->rol(Rol::Admin)->create()->id]);
}

function s97(array $params = [])
{
    return test()->getJson('/api/reportes/cartera-recuperada?'.http_build_query(['desde' => '2026-10-01', 'hasta' => '2026-10-31', ...$params]))->assertOk();
}

/** Ids de las filas del detalle. */
function s97Ids(array $params = []): array
{
    return collect(s97($params)->json('data'))->pluck('id')->all();
}

it('S-97: el período es inclusivo por la fecha del abono y no cruza de mes', function () {
    $c = Cliente::factory()->create();
    $fin = s97Abono($c, 100, '2026-09-30');
    $uno = s97Abono($c, 200, '2026-10-01');
    $treinta = s97Abono($c, 300, '2026-10-31');
    $nov = s97Abono($c, 400, '2026-11-01');

    expect(s97Ids())->toBe([$treinta->id, $uno->id])
        ->and(s97Ids(['desde' => '2026-09-30', 'hasta' => '2026-09-30']))->toBe([$fin->id])
        ->and(s97Ids(['desde' => '2026-11-01', 'hasta' => '2026-11-30']))->toBe([$nov->id]);
});

it('S-97: por defecto toma el mes en curso', function () {
    $c = Cliente::factory()->create();
    $octubre = s97Abono($c, 100, '2026-10-05');
    s97Abono($c, 100, '2026-09-28');

    expect(collect($this->getJson('/api/reportes/cartera-recuperada')->assertOk()->json('data'))->pluck('id')->all())->toBe([$octubre->id]);
});

it('S-97: filtra por cliente, cuenta y aplicación (a una factura o general)', function () {
    $ana = Cliente::factory()->create(['nombre' => 'Ana']);
    $beto = Cliente::factory()->create(['nombre' => 'Beto']);
    $venta = s97Venta($ana, 500000, 'factura', 'FE-77');
    $banco = cuentaBanco();
    $aFactura = s97Abono($ana, 100, '2026-10-10', $venta);
    $general = s97Abono($ana, 200, '2026-10-11');
    $deBeto = s97Abono($beto, 300, '2026-10-12', null, $banco);

    expect(s97Ids(['cliente_id' => $ana->id]))->toBe([$general->id, $aFactura->id])
        ->and(s97Ids(['cuenta_pago_id' => $banco->id]))->toBe([$deBeto->id])
        ->and(s97Ids(['tipo_aplicacion' => 'a_factura']))->toBe([$aFactura->id])
        ->and(s97Ids(['tipo_aplicacion' => 'general']))->toBe([$deBeto->id, $general->id]);

    $filas = collect(s97()->json('data'))->keyBy('id');
    expect($filas[$general->id]['aplicado_a'])->toBe('General')
        ->and($filas[$general->id]['documento'])->toBeNull()
        ->and($filas[$aFactura->id]['documento'])->toMatchArray(['venta_id' => $venta, 'tipo' => 'factura', 'numero' => 'FE-77'])
        ->and($filas[$deBeto->id]['cuenta']['nombre'])->toBe($banco->nombre);
});

it('S-97: q busca sin tildes ni mayúsculas por nombre, documento, número de factura, referencia y nota', function () {
    $jose = Cliente::factory()->create(['nombre' => 'José Peña', 'documento' => '1.098.765.432']);
    $otro = Cliente::factory()->create(['nombre' => 'Otro Cliente', 'documento' => '555']);
    $venta = s97Venta($otro, 400000, 'factura', 'FE-3122112');
    $a = s97Abono($jose, 100, '2026-10-02');
    $b = s97Abono($otro, 200, '2026-10-03', $venta);
    $c = s97Abono($otro, 300, '2026-10-04', null, null, ['referencia' => 'TRF-889', 'nota' => 'Consignación Bancolombia']);

    expect(s97Ids(['q' => 'jose pena']))->toBe([$a->id])
        ->and(s97Ids(['q' => '1098765432']))->toBe([$a->id])
        ->and(s97Ids(['q' => '3122112']))->toBe([$b->id])
        ->and(s97Ids(['q' => 'trf-889']))->toBe([$c->id])
        ->and(s97Ids(['q' => 'consignacion']))->toBe([$c->id]);
});

it('S-97: un abono anulado no cuenta como recuperado y va aparte; el estado solo decide las filas', function () {
    $c = Cliente::factory()->create();
    $vigente = s97Abono($c, 1000, '2026-10-05');
    $anulado = s97Abono($c, 400, '2026-10-06');
    s97Anular($anulado, 'Se registró dos veces');

    foreach (['vigentes', 'anulados', 'todos'] as $estado) {
        expect(s97(['estado' => $estado])->json('totales'))->toMatchArray([
            'total_recuperado' => '1000.00', 'cantidad_abonos' => 1, 'clientes' => 1, 'total_anulado' => '400.00', 'cantidad_anulados' => 1,
        ]);
    }
    expect(s97Ids())->toBe([$vigente->id])
        ->and(s97Ids(['estado' => 'anulados']))->toBe([$anulado->id])
        ->and(s97Ids(['estado' => 'todos']))->toBe([$anulado->id, $vigente->id]);

    $fila = s97(['estado' => 'anulados'])->json('data.0');
    expect($fila['anulado'])->toBeTrue()
        ->and($fila['anulacion']['motivo'])->toBe('Se registró dos veces');
});

it('S-97: los totales cuadran con la suma de lo filtrado y no dependen de la página ni de la vista', function () {
    $ana = Cliente::factory()->create(['nombre' => 'Ana']);
    $beto = Cliente::factory()->create(['nombre' => 'Beto']);
    $banco = cuentaBanco();
    foreach (range(1, 7) as $n) {
        s97Abono($n % 2 ? $ana : $beto, 1000 * $n, '2026-10-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), null, $n <= 3 ? $banco : null);
    }

    $totales = s97(['por_pagina' => 2])->json('totales');
    expect($totales)->toMatchArray(['total_recuperado' => '28000.00', 'cantidad_abonos' => 7, 'clientes' => 2])
        ->and(s97(['por_pagina' => 2, 'page' => 3])->json('totales'))->toBe($totales)
        ->and(s97(['vista' => 'por_cliente'])->json('totales'))->toBe($totales);

    $porCuenta = collect($totales['por_cuenta'])->keyBy('cuenta_id');
    expect($porCuenta[$banco->id])->toMatchArray(['total' => '6000.00', 'cantidad' => 3])
        ->and($porCuenta[cuentaEfectivo()->id])->toMatchArray(['total' => '22000.00', 'cantidad' => 4]);

    // La suma de las filas filtradas es el total.
    $filas = collect(s97(['por_pagina' => 100])->json('data'));
    expect($filas->sum(fn ($f) => (float) $f['valor']))->toBe(28000.0);
    // Y respeta los filtros.
    expect(s97(['cliente_id' => $ana->id])->json('totales.total_recuperado'))->toBe('16000.00');
});

it('S-97: por cliente agrupa, ordena y su saldo actual coincide con GET /clientes/{id}/saldo', function () {
    $ana = Cliente::factory()->create(['nombre' => 'Ana']);
    $beto = Cliente::factory()->create(['nombre' => 'Beto']);
    $ventaAna = s97Venta($ana, 500000);
    $ventaBeto = s97Venta($beto, 300000);
    s97Abono($ana, 100000, '2026-10-02', $ventaAna);
    s97Abono($ana, 50000, '2026-10-09', $ventaAna);
    s97Abono($beto, 200000, '2026-10-05', $ventaBeto);
    s97Anular(s97Abono($beto, 10000, '2026-10-06', $ventaBeto));

    $filas = collect(s97(['vista' => 'por_cliente'])->json('data'));
    expect($filas->pluck('cliente.nombre')->all())->toBe(['Beto', 'Ana']) // mayor recuperado primero
        ->and(collect(s97(['vista' => 'por_cliente', 'orden' => 'nombre'])->json('data'))->pluck('cliente.nombre')->all())->toBe(['Ana', 'Beto']);

    $ana_ = $filas->firstWhere('cliente.id', $ana->id);
    expect($ana_)->toMatchArray(['abonos' => 2, 'recuperado' => '150000.00', 'ultimo_abono' => '2026-10-09']);
    foreach ([$ana, $beto] as $c) {
        $saldo = $this->getJson("/api/clientes/{$c->id}/saldo")->assertOk()->json('saldo');
        expect($filas->firstWhere('cliente.id', $c->id)['saldo_actual'])->toBe($saldo);
    }
});

it('S-97: pagina sin repetir ni saltar abonos de la misma fecha', function () {
    $c = Cliente::factory()->create();
    $ids = collect(range(1, 7))->map(fn () => s97Abono($c, 100, '2026-10-15')->id);

    $vistos = collect(range(1, 3))->flatMap(fn ($p) => s97Ids(['por_pagina' => 3, 'page' => $p]))->all();
    expect($vistos)->toBe($ids->sortDesc()->values()->all())
        ->and(s97(['por_pagina' => 3])->json('total'))->toBe(7)
        ->and(s97(['por_pagina' => 3])->json('last_page'))->toBe(3);
    $this->getJson('/api/reportes/cartera-recuperada?por_pagina=101')->assertUnprocessable();
});

it('S-97: valida el rango y los filtros', function () {
    $this->getJson('/api/reportes/cartera-recuperada?desde=2026-10-31&hasta=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('hasta');
    $this->getJson('/api/reportes/cartera-recuperada?vista=otra')->assertUnprocessable()->assertJsonValidationErrors('vista');
    $this->getJson('/api/reportes/cartera-recuperada?cliente_id=999999')->assertUnprocessable()->assertJsonValidationErrors('cliente_id');
    $this->getJson('/api/reportes/cartera-recuperada?tipo_aplicacion=x')->assertUnprocessable()->assertJsonValidationErrors('tipo_aplicacion');
});

it('S-97: sin N+1: con 50 abonos hace las mismas consultas que con 3, en las dos vistas', function () {
    $clientes = Cliente::factory()->count(5)->create();
    $consultas = function (string $vista): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        s97(['vista' => $vista, 'estado' => 'todos', 'por_pagina' => 100]);

        return count(DB::getQueryLog());
    };
    foreach (range(1, 3) as $n) {
        s97Anular(s97Abono($clientes[$n % 5], 100, '2026-10-0'.$n));
    }
    [$detalle, $porCliente] = [$consultas('detalle'), $consultas('por_cliente')];
    foreach (range(4, 50) as $n) {
        s97Abono($clientes[$n % 5], 100, '2026-10-'.str_pad((string) (($n % 28) + 1), 2, '0', STR_PAD_LEFT), null, $n % 2 ? cuentaBanco() : null);
    }

    expect($consultas('detalle'))->toBe($detalle)
        ->and($consultas('por_cliente'))->toBe($porCliente);
});

it('S-97: contable y admin consultan; mostrador y logística reciben 403', function (Rol $rol, int $estado) {
    actuarComo($rol);
    $this->getJson('/api/reportes/cartera-recuperada')->assertStatus($estado);
})->with([
    'contable' => [Rol::Contable, 200],
    'admin' => [Rol::Admin, 200],
    'mostrador' => [Rol::Mostrador, 403],
    'logística' => [Rol::Logistica, 403],
]);
