<?php

use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\RecaudoDocumento;
use Illuminate\Support\Carbon;

/*
 * RF-25 / RF-26 (S-76): recaudo de documentos de Contapyme (facturas o remisiones a crédito que no
 * son ventas a crédito del sistema). Entra a la caja del día con el recaudo de cartera: suma al neto
 * de su cuenta y, en efectivo, al esperado; no a la meta, ni a la cartera de clientes ni a "Por cobrar".
 */

beforeEach(function () {
    sembrarDemo();
    Carbon::setTestNow('2026-11-20 15:00:00'); // 10:00 en Bogotá
    abrirCaja('2026-11-20', 100000);
});

const DIA_S76 = '2026-11-20';

function recaudo(array $datos = [], string $fecha = DIA_S76)
{
    return test()->postJson("/api/caja/dias/{$fecha}/recaudos", [
        'tipo_documento' => 'factura',
        'numero_documento' => '12345',
        'valor' => 150000,
        'cuenta_pago_id' => cuentaEfectivo()->id,
        ...$datos,
    ]);
}

function resumenS76(): array
{
    return test()->getJson('/api/caja/dias/'.DIA_S76)->assertOk()->json();
}

function cuentaDe(array $resumen, int $cuentaId): array
{
    return collect($resumen['resumen']['cuentas'])->firstWhere('cuenta_pago_id', $cuentaId);
}

it('S-76: un recaudo en efectivo sube el efectivo esperado y el neto de Efectivo; no la meta', function () {
    actuarComo(Rol::Mostrador);
    $antes = resumenS76()['resumen'];

    recaudo(['observacion' => 'cobro en obra'])->assertCreated()
        ->assertJsonPath('documento', 'Factura N.º 12345')
        ->assertJsonPath('valor', '150000.00')
        ->assertJsonPath('fecha', DIA_S76)
        ->assertJsonPath('observacion', 'cobro en obra');

    $d = resumenS76();
    $r = $d['resumen'];
    expect($r['efectivo_esperado'])->toBe(bcadd($antes['efectivo_esperado'], '150000', 2))
        ->and($r['efectivo']['recaudo'])->toBe('150000.00')
        ->and($r['recaudo_cartera'])->toBe('150000.00')
        ->and(cuentaDe($d, cuentaEfectivo()->id)['recaudo'])->toBe('150000.00')
        ->and(cuentaDe($d, cuentaEfectivo()->id)['neto'])->toBe('150000.00')
        // La meta no cambia: el recaudo no es venta del día.
        ->and($r['total_ingreso_dia'])->toBe($antes['total_ingreso_dia'])
        ->and($r['faltante_meta'])->toBe($antes['faltante_meta'])
        ->and($r['totales_movimientos']['recaudos'])->toBe(['cantidad' => 1, 'total' => '150000.00'])
        ->and($r['totales_movimientos']['ingresos']['cantidad'])->toBe(0);
    expect($d['recaudos'])->toHaveCount(1);
});

it('S-76: un recaudo por banco suma al neto de esa cuenta y no al efectivo esperado', function () {
    actuarComo(Rol::Mostrador);
    $antes = resumenS76()['resumen'];

    recaudo(['cuenta_pago_id' => cuentaBanco()->id, 'valor' => '80000.50', 'tipo_documento' => 'remision', 'numero_documento' => 'R-9'])
        ->assertCreated()->assertJsonPath('documento', 'Remisión N.º R-9');

    $d = resumenS76();
    expect($d['resumen']['efectivo_esperado'])->toBe($antes['efectivo_esperado'])
        ->and($d['resumen']['efectivo']['recaudo'])->toBe('0.00')
        ->and(cuentaDe($d, cuentaBanco()->id)['recaudo'])->toBe('80000.50')
        ->and(cuentaDe($d, cuentaBanco()->id)['neto'])->toBe('80000.50')
        ->and($d['resumen']['totales_movimientos']['recaudos'])->toBe(['cantidad' => 1, 'total' => '80000.50']);
});

it('S-76: valida tipo, número, valor y cuenta; un mismo documento admite varios recaudos', function () {
    actuarComo(Rol::Mostrador);

    recaudo(['valor' => 0])->assertUnprocessable()->assertJsonValidationErrors('valor');
    recaudo(['valor' => -5000])->assertUnprocessable()->assertJsonValidationErrors('valor');
    recaudo(['valor' => '10.123'])->assertUnprocessable()->assertJsonValidationErrors('valor');
    recaudo(['tipo_documento' => 'nota'])->assertUnprocessable()->assertJsonValidationErrors('tipo_documento');
    recaudo(['tipo_documento' => null])->assertUnprocessable()->assertJsonValidationErrors('tipo_documento');
    recaudo(['numero_documento' => ''])->assertUnprocessable()->assertJsonValidationErrors('numero_documento');
    recaudo(['numero_documento' => '   '])->assertUnprocessable()->assertJsonValidationErrors('numero_documento');
    recaudo(['numero_documento' => str_repeat('9', 61)])->assertUnprocessable()->assertJsonValidationErrors('numero_documento');
    recaudo(['cuenta_pago_id' => null])->assertUnprocessable()->assertJsonValidationErrors('cuenta_pago_id');
    recaudo(['observacion' => str_repeat('x', 501)])->assertUnprocessable()->assertJsonValidationErrors('observacion');
    $inactiva = cuentaBanco();
    $inactiva->update(['activa' => false]);
    recaudo(['cuenta_pago_id' => $inactiva->id])->assertUnprocessable();

    // Cobros parciales del mismo documento: no se bloquean.
    recaudo(['valor' => 50000])->assertCreated();
    recaudo(['valor' => 50000])->assertCreated();
    expect(RecaudoDocumento::where('numero_documento', '12345')->count())->toBe(2);
});

it('S-76: fecha futura y día cerrado se rechazan; el día cerrado queda de solo lectura', function () {
    actuarComo(Rol::Mostrador);
    $id = recaudo()->assertCreated()->json('id');

    recaudo([], '2026-11-21')->assertUnprocessable();

    $this->postJson('/api/caja/cierres', ['fecha' => DIA_S76, 'efectivo_contado' => 250000])->assertCreated();

    recaudo()->assertUnprocessable();
    $this->postJson("/api/caja/recaudos/{$id}/anular", ['motivo' => 'Ya cerrado'])->assertUnprocessable();
    expect(RecaudoDocumento::count())->toBe(1);
});

it('S-76: el cierre guarda el esperado con el recaudo en efectivo y sin el de banco', function () {
    actuarComo(Rol::Mostrador);
    recaudo()->assertCreated();                                            // 150.000 efectivo
    recaudo(['cuenta_pago_id' => cuentaBanco()->id, 'valor' => 90000])->assertCreated();

    $this->postJson('/api/caja/cierres', ['fecha' => DIA_S76, 'efectivo_contado' => 250000])->assertCreated()
        ->assertJsonPath('efectivo_esperado', '250000.00') // apertura 100.000 + recaudo 150.000
        ->assertJsonPath('diferencia', '0.00');
});

it('S-76: anular pide motivo, deja de sumar y no se anula dos veces', function () {
    actuarComo(Rol::Mostrador);
    $id = recaudo()->assertCreated()->json('id');

    $this->postJson("/api/caja/recaudos/{$id}/anular", [])->assertUnprocessable()->assertJsonValidationErrors('motivo');
    $this->postJson("/api/caja/recaudos/{$id}/anular", ['motivo' => 'Valor equivocado'])->assertCreated()
        ->assertJsonPath('anulacion.motivo', 'Valor equivocado');
    $this->postJson("/api/caja/recaudos/{$id}/anular", ['motivo' => 'Otra vez'])->assertUnprocessable();

    $d = resumenS76();
    expect($d['resumen']['efectivo']['recaudo'])->toBe('0.00')
        ->and($d['resumen']['efectivo_esperado'])->toBe('100000.00')
        ->and($d['resumen']['totales_movimientos']['recaudos'])->toBe(['cantidad' => 0, 'total' => '0.00'])
        // Sigue en la lista, marcado como anulado; nunca se borra.
        ->and($d['recaudos'][0]['anulacion']['motivo'])->toBe('Valor equivocado');
    expect(RecaudoDocumento::count())->toBe(1);
});

it('S-76: no toca la cartera de clientes ni "Por cobrar"', function () {
    actuarComo(Rol::Mostrador);
    $cliente = Cliente::factory()->create(['nombre' => 'Taller Recaudo']);
    ventaCreditoCerrada($cliente, 1, 120000);

    $cartera = $this->getJson('/api/cartera')->assertOk()->json();
    $detalle = $this->getJson("/api/clientes/{$cliente->id}/cartera")->assertOk()->json();
    actuarComo(Rol::Contable);
    $porCobrar = $this->getJson('/api/reportes/cartera-clientes')->assertOk()->json();

    actuarComo(Rol::Mostrador);
    recaudo(['numero_documento' => 'FE-1', 'valor' => 120000])->assertCreated();

    expect($this->getJson('/api/cartera')->assertOk()->json())->toBe($cartera);
    expect($this->getJson("/api/clientes/{$cliente->id}/cartera")->assertOk()->json())->toBe($detalle);
    actuarComo(Rol::Contable);
    expect($this->getJson('/api/reportes/cartera-clientes')->assertOk()->json())->toBe($porCobrar);
});

it('S-76: permisos: solo el mostrador registra y anula; contable y admin consultan; mostrador no consulta', function () {
    actuarComo(Rol::Mostrador);
    $id = recaudo()->assertCreated()->json('id');
    $this->getJson('/api/recaudos-documento?desde=2026-11-01&hasta=2026-11-30')->assertForbidden();

    foreach ([Rol::Admin, Rol::Logistica, Rol::Contable] as $rol) {
        actuarComo($rol);
        recaudo()->assertForbidden();
        $this->postJson("/api/caja/recaudos/{$id}/anular", ['motivo' => 'No puedo'])->assertForbidden();
    }

    actuarComo(Rol::Contable);
    $this->getJson('/api/recaudos-documento?desde=2026-11-01&hasta=2026-11-30')->assertOk();
    actuarComo(Rol::Admin);
    $this->getJson('/api/recaudos-documento?desde=2026-11-01&hasta=2026-11-30')->assertOk();
    actuarComo(Rol::Logistica);
    $this->getJson('/api/recaudos-documento?desde=2026-11-01&hasta=2026-11-30')->assertForbidden();
});

it('S-76: la consulta del contable pagina, filtra y totaliza por cuenta sin los anulados', function () {
    actuarComo(Rol::Mostrador);
    recaudo(['numero_documento' => '12345', 'valor' => 150000, 'observacion' => 'cobro en obra'])->assertCreated();
    recaudo(['numero_documento' => '777', 'valor' => 60000, 'cuenta_pago_id' => cuentaBanco()->id])->assertCreated();
    recaudo(['numero_documento' => 'R-1', 'tipo_documento' => 'remision', 'valor' => 40000])->assertCreated();
    $anulado = recaudo(['numero_documento' => '999', 'valor' => 500000])->assertCreated()->json('id');
    $this->postJson("/api/caja/recaudos/{$anulado}/anular", ['motivo' => 'Duplicado'])->assertCreated();

    actuarComo(Rol::Contable);
    $r = $this->getJson('/api/recaudos-documento?desde=2026-11-01&hasta=2026-11-30')->assertOk();
    expect($r->json('meta.total'))->toBe(4)
        ->and($r->json('totales.total_vigente'))->toBe('250000.00')
        ->and($r->json('totales.cantidad_vigente'))->toBe(3)
        ->and(collect($r->json('totales.por_cuenta'))->map(fn ($c) => [$c['nombre'], $c['total'], $c['cantidad']])->all())
        ->toBe([['Efectivo', '190000.00', 2], ['Bancolombia', '60000.00', 1]]);
    // El anulado se lista con su motivo, pero no suma.
    expect(collect($r->json('data'))->firstWhere('id', $anulado)['anulacion']['motivo'])->toBe('Duplicado');

    // Filtros: tipo, cuenta y búsqueda en número u observación.
    expect($this->getJson('/api/recaudos-documento?desde=2026-11-01&hasta=2026-11-30&tipo=remision')->json('totales.total_vigente'))->toBe('40000.00');
    expect($this->getJson('/api/recaudos-documento?desde=2026-11-01&hasta=2026-11-30&cuenta_pago_id='.cuentaBanco()->id)->json('meta.total'))->toBe(1);
    expect($this->getJson('/api/recaudos-documento?desde=2026-11-01&hasta=2026-11-30&q=obra')->json('data.0.numero_documento'))->toBe('12345');
    expect($this->getJson('/api/recaudos-documento?desde=2026-11-01&hasta=2026-11-30&q=77')->json('meta.total'))->toBe(1);
    // Fuera del rango, nada; la paginación respeta por_pagina; un rango invertido es 422.
    expect($this->getJson('/api/recaudos-documento?desde=2026-12-01&hasta=2026-12-31')->json('totales.cantidad_vigente'))->toBe(0);
    expect($this->getJson('/api/recaudos-documento?desde=2026-11-01&hasta=2026-11-30&por_pagina=2')->json('data'))->toHaveCount(2);
    $this->getJson('/api/recaudos-documento?desde=2026-11-30&hasta=2026-11-01')->assertUnprocessable();
});

it('S-76: los recaudos son inmutables', function () {
    actuarComo(Rol::Mostrador);
    $recaudo = RecaudoDocumento::find(recaudo()->assertCreated()->json('id'));

    expect(fn () => $recaudo->update(['valor' => 1]))->toThrow(\App\Exceptions\RegistroInmutableException::class)
        ->and(fn () => $recaudo->delete())->toThrow(\App\Exceptions\RegistroInmutableException::class);
});
