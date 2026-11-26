<?php

use App\Enums\Rol;
use App\Models\Empleado;
use App\Models\MovimientoEmpleado;
use App\Models\User;
use App\Services\EmpleadoService;
use Illuminate\Support\Carbon;

/*
 * RF-27, S-81 (ajusta S-56 y S-57): el mostrador elige al empleado con su saldo, abona una parte o
 * todo, por una cuenta de pago o por descuento de nómina (para el saldo es indiferente). Resumen y
 * estado de cuenta siguen siendo solo del admin.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-11-20 15:00:00'); // 10:00 en Bogotá
    abrirCaja('2026-11-20', 100000);
});

afterEach(fn () => Carbon::setTestNow());

function s81Empleado(string $nombre, int $apertura = 0, bool $activo = true): Empleado
{
    $anterior = auth()->user();
    actuarComo(Rol::Admin);
    $id = test()->postJson('/api/empleados', ['nombre' => $nombre, 'documento' => '10'.crc32($nombre)] + ($apertura > 0 ? ['saldo_apertura' => $apertura] : []))
        ->assertCreated()->json('id');
    $anterior ? test()->actingAs($anterior) : null;
    $e = Empleado::findOrFail($id);
    if (! $activo) {
        $e->update(['activo' => false]);
    }

    return $e;
}

function s81Abonar(Empleado $e, int $valor, array $extra = [])
{
    return test()->postJson("/api/empleados/{$e->id}/abonos", ['valor' => $valor, ...$extra]);
}

/** Saldo de un empleado en la lista del mostrador. */
function s81SaldoEnLista(Empleado $e): ?string
{
    return collect(test()->getJson('/api/empleados')->assertOk()->json('data'))->firstWhere('id', $e->id)['saldo'] ?? null;
}

it('S-81: la lista del mostrador trae el saldo de cada activo: préstamo, abono parcial y abono total', function () {
    $natalia = s81Empleado('Natalia', 30000);
    $beto = s81Empleado('Beto');
    s81Empleado('Inactivo', 0, false);
    actuarComo(Rol::Mostrador);

    $lista = $this->getJson('/api/empleados')->assertOk()->json('data');
    expect(collect($lista)->pluck('nombre')->all())->toBe(['Beto', 'Natalia'])
        ->and(collect($lista)->pluck('saldo', 'nombre')->all())->toBe(['Beto' => '0.00', 'Natalia' => '30000.00']);

    $this->postJson("/api/empleados/{$beto->id}/prestamos", ['valor' => 50000])->assertCreated();
    expect(s81SaldoEnLista($beto))->toBe('50000.00');
    s81Abonar($beto, 20000, ['cuenta_pago_id' => cuentaEfectivo()->id])->assertCreated();
    expect(s81SaldoEnLista($beto))->toBe('30000.00');
    s81Abonar($natalia, 30000, ['cuenta_pago_id' => cuentaEfectivo()->id])->assertCreated();
    expect(s81SaldoEnLista($natalia))->toBe('0.00');
});

it('S-81: la lista sale en una sola consulta, sin importar cuántos empleados haya', function () {
    foreach (['Ana', 'Beto', 'Carla', 'Dario'] as $i => $n) {
        s81Empleado($n, 1000 * ($i + 1));
    }
    actuarComo(Rol::Mostrador);

    $consultas = 0;
    DB::listen(function ($q) use (&$consultas) {
        if (str_contains($q->sql, 'empleados')) {
            $consultas++;
        }
    });
    $lista = app(EmpleadoService::class)->activosConSaldo();

    expect($lista)->toHaveCount(4)->and($consultas)->toBe(1)
        ->and($lista->pluck('saldo')->all())->toBe(['1000.00', '2000.00', '3000.00', '4000.00']);
});

it('S-81: el mostrador sigue sin resumen ni estado de cuenta', function () {
    $e = s81Empleado('Natalia', 10000);
    actuarComo(Rol::Mostrador);

    $this->getJson('/api/empleados/resumen')->assertForbidden();
    $this->getJson("/api/empleados/{$e->id}/estado-cuenta")->assertForbidden();
});

it('S-81: abono parcial y abono total por caja; el efectivo esperado sube', function () {
    $e = s81Empleado('Natalia', 30000);
    actuarComo(Rol::Mostrador);

    s81Abonar($e, 10000, ['forma' => 'caja', 'cuenta_pago_id' => cuentaEfectivo()->id])->assertCreated();
    expect(s81SaldoEnLista($e))->toBe('20000.00');
    s81Abonar($e, 20000, ['forma' => 'caja', 'cuenta_pago_id' => cuentaBanco()->id])->assertCreated();
    expect(s81SaldoEnLista($e))->toBe('0.00');

    $r = $this->getJson('/api/caja/dias/2026-11-20')->assertOk()->json('resumen');
    expect($r['efectivo_esperado'])->toBe('110000.00') // solo los 10.000 en Efectivo
        ->and(collect($r['cuentas'])->firstWhere('cuenta_pago_id', cuentaBanco()->id)['abonos_empleados'])->toBe('20000.00');
});

it('S-81 (S-56): el abono por nómina baja el saldo y no cambia el efectivo del día', function () {
    $e = s81Empleado('Natalia', 30000);
    actuarComo(Rol::Mostrador);
    $antes = $this->getJson('/api/caja/dias/2026-11-20')->json('resumen');

    s81Abonar($e, 20000, ['forma' => 'nomina'])->assertCreated()->assertJsonPath('forma', 'nomina')->assertJsonPath('cuenta', null);

    $despues = $this->getJson('/api/caja/dias/2026-11-20')->json('resumen');
    expect(s81SaldoEnLista($e))->toBe('10000.00')
        ->and($despues['efectivo_esperado'])->toBe($antes['efectivo_esperado'])
        ->and($despues['efectivo_neto_dia'])->toBe($antes['efectivo_neto_dia'])
        ->and($despues['totales_movimientos']['empleados']['abonos_nomina'])->toBe(['cantidad' => 1, 'total' => '20000.00']);
});

it('S-81: un abono mayor que la deuda se rechaza con el saldo en el mensaje', function () {
    $e = s81Empleado('Natalia', 30000);
    actuarComo(Rol::Mostrador);

    s81Abonar($e, 30001, ['cuenta_pago_id' => cuentaEfectivo()->id])->assertUnprocessable()
        ->assertJsonPath('message', 'El abono supera lo que debe el empleado ($ 30.000).')
        ->assertJsonPath('saldo', '30000.00');
    s81Abonar($e, 30001, ['forma' => 'nomina'])->assertUnprocessable()->assertJsonPath('saldo', '30000.00');
    expect(s81SaldoEnLista($e))->toBe('30000.00');
});

it('S-81: un abono sin forma se registra por caja', function () {
    $e = s81Empleado('Natalia', 30000);
    actuarComo(Rol::Mostrador);

    s81Abonar($e, 5000, ['cuenta_pago_id' => cuentaEfectivo()->id])->assertCreated()
        ->assertJsonPath('forma', 'caja')->assertJsonPath('cuenta.id', cuentaEfectivo()->id);
});

it('S-57: dos abonos por el total, uno tras otro, no dejan el saldo negativo', function () {
    // El servicio lee el saldo con el empleado bloqueado (lockForUpdate) dentro de la transacción: el
    // segundo abono ve el saldo que dejó el primero. Aquí se envían seguidos, como un doble clic.
    $e = s81Empleado('Natalia', 30000);
    actuarComo(Rol::Mostrador);

    s81Abonar($e, 30000, ['cuenta_pago_id' => cuentaEfectivo()->id])->assertCreated();
    s81Abonar($e, 30000, ['forma' => 'nomina'])->assertUnprocessable()->assertJsonPath('saldo', '0.00');

    expect(s81SaldoEnLista($e))->toBe('0.00')
        ->and(MovimientoEmpleado::where('empleado_id', $e->id)->where('tipo', 'abono')->count())->toBe(1);
});

it('S-81: el admin sigue sin registrar préstamos ni abonos; logística 403 en todo', function (Rol $rol) {
    $e = s81Empleado('Natalia', 30000);
    actuarComo($rol);

    $this->postJson("/api/empleados/{$e->id}/prestamos", ['valor' => 1000])->assertForbidden();
    s81Abonar($e, 1000, ['cuenta_pago_id' => cuentaEfectivo()->id])->assertForbidden();
    s81Abonar($e, 1000, ['forma' => 'nomina'])->assertForbidden();
    if ($rol === Rol::Logistica) {
        $this->getJson('/api/empleados')->assertForbidden();
    }
})->with([Rol::Admin, Rol::Logistica]);
