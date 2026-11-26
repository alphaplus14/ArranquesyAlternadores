<?php

use App\Enums\Rol;
use App\Models\Empleado;
use Illuminate\Support\Carbon;

/*
 * RF-27 (S-55 a S-57) en la Caja del día del mostrador: los préstamos y abonos de empleados del día
 * salen en GET /caja/dias/{fecha} (lista y totales_movimientos.empleados, sin anulados) y sus totales
 * coinciden con el detalle del efectivo esperado. El mostrador nunca recibe saldos de empleados.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-11-20 15:00:00'); // 10:00 en Bogotá
    abrirCaja('2026-11-20', 100000);
});

afterEach(fn () => Carbon::setTestNow());

/** Empleado activo con saldo de apertura (no toca la caja), creado como admin. */
function ecmEmpleado(string $nombre = 'Jhon', int $apertura = 0): Empleado
{
    $anterior = auth()->user();
    actuarComo(Rol::Admin);
    $datos = ['nombre' => $nombre] + ($apertura > 0 ? ['saldo_apertura' => $apertura] : []);
    $id = test()->postJson('/api/empleados', $datos)->assertCreated()->json('id');
    $anterior ? test()->actingAs($anterior) : null;

    return Empleado::findOrFail($id);
}

function ecmPrestar(Empleado $e, int|string $valor)
{
    return test()->postJson("/api/empleados/{$e->id}/prestamos", ['valor' => $valor]);
}

function ecmAbonar(Empleado $e, int|string $valor, string $forma = 'caja', ?int $cuentaId = null)
{
    $datos = ['valor' => $valor, 'forma' => $forma];
    if ($forma === 'caja') {
        $datos['cuenta_pago_id'] = $cuentaId ?? cuentaEfectivo()->id;
    }

    return test()->postJson("/api/empleados/{$e->id}/abonos", $datos);
}

function ecmDia(): array
{
    return test()->getJson('/api/caja/dias/2026-11-20')->assertOk()->json();
}

/** ¿Hay alguna clave "saldo" (a cualquier profundidad) en la respuesta? */
function ecmTieneSaldo(array $datos): bool
{
    foreach ($datos as $clave => $valor) {
        if ($clave === 'saldo' || (is_array($valor) && ecmTieneSaldo($valor))) {
            return true;
        }
    }

    return false;
}

it('RF-27 (S-56): el préstamo sale del efectivo, aparece en la lista del día y baja el efectivo esperado', function () {
    $e = ecmEmpleado('Jhon');
    $mostrador = actuarComo(Rol::Mostrador);
    $antes = ecmDia()['resumen']['efectivo_esperado'];

    ecmPrestar($e, 50000)->assertCreated();

    $d = ecmDia();
    expect($antes)->toBe('100000.00')
        ->and($d['resumen']['efectivo_esperado'])->toBe('50000.00')
        ->and($d['resumen']['efectivo']['prestamos_empleados'])->toBe('50000.00')
        ->and($d['resumen']['totales_movimientos']['empleados']['prestamos'])->toBe(['cantidad' => 1, 'total' => '50000.00'])
        ->and($d['movimientos_empleado'])->toHaveCount(1);
    $m = $d['movimientos_empleado'][0];
    expect($m['tipo'])->toBe('prestamo')
        ->and($m['empleado'])->toBe(['id' => $e->id, 'nombre' => 'Jhon'])
        ->and($m['valor'])->toBe('50000.00')
        ->and($m['forma'])->toBeNull()
        ->and($m['cuenta']['id'])->toBe(cuentaEfectivo()->id)
        ->and($m['usuario']['id'])->toBe($mostrador->id)
        ->and($m['anulacion'])->toBeNull();
});

it('RF-27 (S-53): con la caja de hoy cerrada no entra el préstamo', function () {
    $e = ecmEmpleado();
    actuarComo(Rol::Mostrador);
    $this->postJson('/api/caja/cierres', ['fecha' => '2026-11-20', 'efectivo_contado' => 100000])->assertCreated();

    ecmPrestar($e, 50000)->assertUnprocessable()->assertJsonPath('caja_cerrada', '2026-11-20');
    expect(ecmDia()['movimientos_empleado'])->toBe([]);
});

it('RF-27 (S-56): abonos por caja a Efectivo y a otra cuenta, y por nómina, suman donde corresponde', function () {
    $e = ecmEmpleado('Jhon', 200000); // la apertura no toca la caja ni sale en la lista
    actuarComo(Rol::Mostrador);

    ecmAbonar($e, 20000)->assertCreated();
    ecmAbonar($e, 15000, 'caja', cuentaBanco()->id)->assertCreated();
    ecmAbonar($e, 10000, 'nomina')->assertCreated()->assertJsonPath('cuenta', null);

    $d = ecmDia();
    $r = $d['resumen'];
    $t = $r['totales_movimientos']['empleados'];
    expect($r['efectivo']['abonos_empleados'])->toBe('20000.00')
        ->and($r['efectivo_esperado'])->toBe('120000.00') // nómina y banco no mueven el efectivo
        ->and(collect($r['cuentas'])->firstWhere('cuenta_pago_id', cuentaBanco()->id)['abonos_empleados'])->toBe('15000.00')
        ->and($t['prestamos'])->toBe(['cantidad' => 0, 'total' => '0.00'])
        ->and($t['abonos_caja'])->toBe(['cantidad' => 2, 'total' => '35000.00'])
        ->and($t['abonos_nomina'])->toBe(['cantidad' => 1, 'total' => '10000.00'])
        ->and(collect($d['movimientos_empleado'])->pluck('forma')->all())->toBe(['caja', 'caja', 'nomina'])
        ->and($r['total_ingreso_dia'])->toBe('0.00'); // no es venta ni cuenta para la meta
});

it('RF-27 (S-57): abono mayor que la deuda, en 0 o negativo se rechaza', function () {
    $e = ecmEmpleado('Jhon', 30000);
    actuarComo(Rol::Mostrador);

    ecmAbonar($e, 30001)->assertUnprocessable()->assertJsonPath('campo', 'valor')->assertJsonMissingPath('saldo');
    ecmAbonar($e, 0)->assertUnprocessable()->assertJsonValidationErrors('valor');
    ecmAbonar($e, -5000, 'nomina')->assertUnprocessable()->assertJsonValidationErrors('valor');
    ecmPrestar($e, 0)->assertUnprocessable()->assertJsonValidationErrors('valor');

    expect(ecmDia()['movimientos_empleado'])->toBe([]);
});

it('RF-27 (S-54): admin, logística y contable no registran préstamos ni abonos', function (Rol $rol) {
    $e = ecmEmpleado('Jhon', 30000);
    actuarComo($rol);

    ecmPrestar($e, 1000)->assertForbidden();
    ecmAbonar($e, 1000)->assertForbidden();
    ecmAbonar($e, 1000, 'nomina')->assertForbidden();
})->with([Rol::Admin, Rol::Logistica, Rol::Contable]);

it('RF-27 (S-57): el mostrador no recibe saldos ni en la lista de empleados ni en el día de caja', function () {
    $e = ecmEmpleado('Jhon', 80000);
    actuarComo(Rol::Mostrador);
    ecmPrestar($e, 50000)->assertCreated();
    ecmAbonar($e, 20000)->assertCreated();

    $lista = $this->getJson('/api/empleados')->assertOk()->json();
    expect(array_keys($lista['data'][0]))->toBe(['id', 'nombre', 'documento'])
        ->and(ecmTieneSaldo($lista))->toBeFalse();

    $dia = ecmDia();
    expect($dia['movimientos_empleado'])->toHaveCount(2)
        ->and(ecmTieneSaldo($dia))->toBeFalse()
        ->and(array_keys($dia['movimientos_empleado'][0]['empleado']))->toBe(['id', 'nombre']);
});

it('RF-27: solo el admin anula; el anulado sigue en la lista, marcado, y no suma ni cuenta', function () {
    $e = ecmEmpleado('Jhon');
    actuarComo(Rol::Mostrador);
    ecmPrestar($e, 50000)->assertCreated();
    $abono = ecmAbonar($e, 20000)->assertCreated()->json('id');
    ecmAbonar($e, 10000, 'nomina')->assertCreated();

    $this->postJson("/api/empleados/movimientos/{$abono}/anulaciones", ['motivo' => 'Error de digitación'])->assertForbidden();

    $admin = actuarComo(Rol::Admin);
    $this->postJson("/api/empleados/movimientos/{$abono}/anulaciones", ['motivo' => 'Error de digitación'])->assertCreated();

    actuarComo(Rol::Mostrador);
    $d = ecmDia();
    $r = $d['resumen'];
    $anulado = collect($d['movimientos_empleado'])->firstWhere('id', $abono);
    expect($anulado['anulacion']['motivo'])->toBe('Error de digitación')
        ->and($anulado['anulacion']['usuario']['id'])->toBe($admin->id)
        ->and($r['totales_movimientos']['empleados']['abonos_caja'])->toBe(['cantidad' => 0, 'total' => '0.00'])
        ->and($r['totales_movimientos']['empleados']['prestamos'])->toBe(['cantidad' => 1, 'total' => '50000.00'])
        ->and($r['efectivo']['abonos_empleados'])->toBe('0.00')
        ->and($r['efectivo_esperado'])->toBe('50000.00');
});

it('RF-27: los totales de empleados coinciden con el detalle del efectivo y no entran en gastos ni ingresos', function () {
    $e = ecmEmpleado('Jhon', 100000);
    $f = ecmEmpleado('Ana');
    actuarComo(Rol::Mostrador);
    ecmPrestar($e, 40000)->assertCreated();
    ecmPrestar($f, 25000)->assertCreated();
    ecmAbonar($e, 30000)->assertCreated();
    ecmAbonar($f, 5000)->assertCreated();
    ecmAbonar($e, 12000, 'caja', cuentaBanco()->id)->assertCreated();
    ecmAbonar($e, 8000, 'nomina')->assertCreated();

    $r = ecmDia()['resumen'];
    $t = $r['totales_movimientos'];
    $efectivoAbonos = collect($r['cuentas'])->firstWhere('cuenta_pago_id', cuentaEfectivo()->id)['abonos_empleados'];
    expect($t['empleados']['prestamos']['total'])->toBe($r['efectivo']['prestamos_empleados'])->toBe('65000.00')
        ->and($t['empleados']['prestamos']['cantidad'])->toBe(2)
        ->and($efectivoAbonos)->toBe($r['efectivo']['abonos_empleados'])->toBe('35000.00')
        ->and($t['empleados']['abonos_caja'])->toBe(['cantidad' => 3, 'total' => '47000.00']) // 35.000 en Efectivo + 12.000 en banco
        ->and($t['empleados']['abonos_nomina'])->toBe(['cantidad' => 1, 'total' => '8000.00'])
        ->and($r['efectivo_esperado'])->toBe('70000.00') // 100.000 − 65.000 + 35.000
        ->and($t['gastos'])->toBe(['cantidad' => 0, 'total' => '0.00'])
        ->and($t['ingresos'])->toBe(['cantidad' => 0, 'total' => '0.00']);
});
