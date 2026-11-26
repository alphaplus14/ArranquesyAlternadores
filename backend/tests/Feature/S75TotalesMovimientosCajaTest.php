<?php

use App\Enums\Rol;
use Illuminate\Support\Carbon;

/*
 * RF-26 (S-75): totales de la pestaña Movimientos de la caja del día. Gastos = gasto vario, fijo y
 * cuotas (financiero); ingresos = otros ingresos e ingresos externos de todas las cuentas. Sin
 * anulados. Los totales coinciden con las líneas del detalle del efectivo esperado.
 */

beforeEach(function () {
    sembrarDemo();
    Carbon::setTestNow('2026-11-20 15:00:00'); // 10:00 en Bogotá
    abrirCaja('2026-11-20', 100000);
});

/** RF-27: totales de empleados de un día sin préstamos ni abonos. */
const SIN_EMPLEADOS = ['prestamos' => ['cantidad' => 0, 'total' => '0.00'], 'abonos_caja' => ['cantidad' => 0, 'total' => '0.00'], 'abonos_nomina' => ['cantidad' => 0, 'total' => '0.00']];

function totalesMovimientos(): array
{
    return test()->getJson('/api/caja/dias/2026-11-20')->assertOk()->json('resumen');
}

it('S-75: día sin movimientos: 0 y 0', function () {
    actuarComo(Rol::Mostrador);

    expect(totalesMovimientos()['totales_movimientos'])->toBe([
        'gastos' => ['cantidad' => 0, 'total' => '0.00'],
        'ingresos' => ['cantidad' => 0, 'total' => '0.00'],
        'recaudos' => ['cantidad' => 0, 'total' => '0.00'],
        'empleados' => SIN_EMPLEADOS,
    ]);
});

it('S-75: gastos e ingresos mezclados; las ventas no entran', function () {
    actuarComo(Rol::Mostrador);
    movimientoCaja('COMISION', 20000)->assertCreated();                                 // vario
    movimientoCaja('Servicios públicos (Internet)', 95000)->assertCreated();           // fijo
    movimientoCaja('Banco Bogotá', 300000.5)->assertCreated();                          // financiero
    movimientoCaja('CAJA GRAL', 200000)->assertCreated();                               // ingreso externo
    movimientoCaja('SERVICIO DE TORNO', 45000, cuentaBanco())->assertCreated();         // otro ingreso, en banco
    venderComoMostrador('RN-4410');                                                     // venta de contado: no es movimiento

    expect(totalesMovimientos()['totales_movimientos'])->toBe([
        'gastos' => ['cantidad' => 3, 'total' => '415000.50'],
        'ingresos' => ['cantidad' => 2, 'total' => '245000.00'],
        'recaudos' => ['cantidad' => 0, 'total' => '0.00'],
        'empleados' => SIN_EMPLEADOS,
    ]);
});

it('S-75: un movimiento anulado no suma ni cuenta', function () {
    actuarComo(Rol::Mostrador);
    movimientoCaja('COMISION', 20000)->assertCreated();
    $gasto = movimientoCaja('EMPRESA', 7000)->assertCreated()->json('id');
    movimientoCaja('CAJA GRAL', 200000)->assertCreated();
    $ingreso = movimientoCaja('OTROS', 15000)->assertCreated()->json('id');

    $this->postJson("/api/caja/movimientos/{$gasto}/anulaciones", ['motivo' => 'Valor equivocado'])->assertCreated();
    $this->postJson("/api/caja/movimientos/{$ingreso}/anulaciones", ['motivo' => 'Duplicado'])->assertCreated();

    $respuesta = $this->getJson('/api/caja/dias/2026-11-20')->assertOk();
    expect($respuesta->json('resumen.totales_movimientos'))->toBe([
        'gastos' => ['cantidad' => 1, 'total' => '20000.00'],
        'ingresos' => ['cantidad' => 1, 'total' => '200000.00'],
        'recaudos' => ['cantidad' => 0, 'total' => '0.00'],
        'empleados' => SIN_EMPLEADOS,
    ]);
    // La lista sigue trayendo los cuatro (los anulados, marcados), solo los totales los excluyen.
    expect($respuesta->json('movimientos'))->toHaveCount(4);
});

it('S-75: los totales coinciden con las líneas del detalle del efectivo esperado', function () {
    actuarComo(Rol::Mostrador);
    movimientoCaja('COMISION', 20000)->assertCreated();
    movimientoCaja('Nómina Jhon', 600000)->assertCreated();
    movimientoCaja('CAJA GRAL', 200000)->assertCreated();
    movimientoCaja('MANTENIMIENTO DE ARRANQUE', 30000)->assertCreated();

    $r = totalesMovimientos();
    $t = $r['totales_movimientos'];

    // Gastos = "Egresos (gastos, fijos y cuotas)".
    expect($t['gastos']['total'])->toBe($r['efectivo']['egresos'])->toBe($r['egresos']['total'])->toBe('620000.00');
    // Ingresos = "Otros ingresos" + "Ingresos externos" (todas las cuentas; aquí todo en efectivo).
    expect($t['ingresos']['total'])
        ->toBe(bcadd($r['otros_ingresos'], $r['ingresos_externos'], 2))
        ->toBe(bcadd($r['efectivo']['otros_ingresos'], $r['efectivo']['ingresos_externos'], 2))
        ->toBe('230000.00');
});
