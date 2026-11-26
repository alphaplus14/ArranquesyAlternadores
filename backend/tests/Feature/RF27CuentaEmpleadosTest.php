<?php

use App\Enums\Rol;
use App\Models\Empleado;
use App\Models\MovimientoEmpleado;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/*
 * RF-27 (S-55 a S-57): cuenta de empleados. Préstamos y abonos (mostrador), empleados, apertura y
 * anulaciones (admin), saldo, estado de cuenta, resumen y efecto en la caja del día.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-11-20 15:00:00'); // 10:00 en Bogotá
});

afterEach(fn () => Carbon::setTestNow());

/** Crea el empleado como admin y deja la sesión como estaba. */
function empCrear(string $nombre = 'Jhon', array $extra = []): Empleado
{
    $anterior = auth()->user();
    actuarComo(Rol::Admin);
    $id = test()->postJson('/api/empleados', ['nombre' => $nombre, ...$extra])->assertCreated()->json('id');
    $anterior ? test()->actingAs($anterior) : null;

    return Empleado::findOrFail($id);
}

function empPrestar(Empleado $e, int $valor, array $extra = [])
{
    return test()->postJson("/api/empleados/{$e->id}/prestamos", ['valor' => $valor, ...$extra]);
}

function empAbonar(Empleado $e, int $valor, string $forma = 'caja', ?int $cuentaId = null, array $extra = [])
{
    $datos = ['valor' => $valor, 'forma' => $forma, ...$extra];
    if ($forma === 'caja') {
        $datos['cuenta_pago_id'] = $cuentaId ?? cuentaEfectivo()->id;
    }

    return test()->postJson("/api/empleados/{$e->id}/abonos", $datos);
}

/** Saldo según el resumen del admin (cambia la sesión al admin y la devuelve). */
function empSaldo(Empleado $e): string
{
    $anterior = auth()->user();
    actuarComo(Rol::Admin);
    $fila = collect(test()->getJson('/api/empleados/resumen')->assertOk()->json('data'))->firstWhere('id', $e->id);
    $anterior ? test()->actingAs($anterior) : null;

    return $fila['saldo'];
}

function empResumenCaja(string $fecha = '2026-11-20'): array
{
    return test()->getJson("/api/caja/dias/{$fecha}")->assertOk()->json('resumen');
}

it('RF-27: el mostrador registra un préstamo: hoy, desde Efectivo, sin forma', function () {
    $e = empCrear();
    $mostrador = actuarComo(Rol::Mostrador);

    empPrestar($e, 100000, ['nota' => 'Para el arriendo'])->assertCreated()
        ->assertJsonPath('tipo', 'prestamo')
        ->assertJsonPath('fecha', '2026-11-20')
        ->assertJsonPath('forma', null)
        ->assertJsonPath('cuenta.id', cuentaEfectivo()->id)
        ->assertJsonPath('usuario.id', $mostrador->id)
        ->assertJsonMissingPath('saldo');

    expect(empSaldo($e))->toBe('100000.00');
});

it('RF-27 (S-56): un préstamo no acepta cuenta, forma ni fecha', function () {
    $e = empCrear();
    actuarComo(Rol::Mostrador);

    empPrestar($e, 1000, ['cuenta_pago_id' => cuentaBanco()->id])->assertUnprocessable()->assertJsonValidationErrors('cuenta_pago_id');
    empPrestar($e, 1000, ['forma' => 'caja'])->assertUnprocessable()->assertJsonValidationErrors('forma');
    empPrestar($e, 1000, ['fecha' => '2026-11-19'])->assertUnprocessable()->assertJsonValidationErrors('fecha');
    empPrestar($e, 0)->assertUnprocessable()->assertJsonValidationErrors('valor');
});

it('RF-27: abono por caja feliz y saldo = préstamos − abonos', function () {
    $e = empCrear();
    actuarComo(Rol::Mostrador);
    empPrestar($e, 100000)->assertCreated();
    empPrestar($e, 50000)->assertCreated();

    empAbonar($e, 30000, 'caja', cuentaBanco()->id)->assertCreated()
        ->assertJsonPath('tipo', 'abono')
        ->assertJsonPath('forma', 'caja')
        ->assertJsonPath('cuenta.id', cuentaBanco()->id);
    empAbonar($e, 20000, 'nomina')->assertCreated();

    expect(empSaldo($e))->toBe('100000.00');
});

it('RF-27 (S-57, S-81): un abono mayor que la deuda se rechaza y dice cuánto debe', function () {
    $e = empCrear();
    actuarComo(Rol::Mostrador);
    empPrestar($e, 40000)->assertCreated();

    $r = empAbonar($e, 40001)->assertUnprocessable()->assertJsonPath('saldo', '40000.00');
    expect($r->json('message'))->toBe('El abono supera lo que debe el empleado ($ 40.000).');
    empAbonar($e, 40000)->assertCreated();
    expect(empSaldo($e))->toBe('0.00');
});

it('RF-27 (S-56): abono por nómina sin cuenta y sin efecto en la caja', function () {
    $e = empCrear();
    actuarComo(Rol::Mostrador);
    empPrestar($e, 50000)->assertCreated();
    $antes = empResumenCaja();

    empAbonar($e, 20000, 'nomina')->assertCreated()->assertJsonPath('cuenta', null);
    test()->postJson("/api/empleados/{$e->id}/abonos", ['valor' => 1000, 'forma' => 'nomina', 'cuenta_pago_id' => cuentaEfectivo()->id])
        ->assertUnprocessable();

    $despues = empResumenCaja();
    expect($despues['efectivo_neto_dia'])->toBe($antes['efectivo_neto_dia'])
        ->and($despues['abonos_empleados'])->toBe('0.00');
    expect(empSaldo($e))->toBe('30000.00');
});

it('RF-27 (S-56): el abono por caja exige una cuenta activa', function () {
    $e = empCrear();
    actuarComo(Rol::Mostrador);
    empPrestar($e, 50000)->assertCreated();

    test()->postJson("/api/empleados/{$e->id}/abonos", ['valor' => 1000, 'forma' => 'caja'])
        ->assertUnprocessable()->assertJsonPath('campo', 'cuenta_pago_id');
    $inactiva = cuentaBanco();
    $inactiva->update(['activa' => false]);
    empAbonar($e, 1000, 'caja', $inactiva->id)->assertUnprocessable()->assertJsonPath('campo', 'cuenta_pago_id');
    // S-81: sin forma, el abono es por caja; sin cuenta, el servicio la exige.
    test()->postJson("/api/empleados/{$e->id}/abonos", ['valor' => 1000])->assertUnprocessable()->assertJsonPath('campo', 'cuenta_pago_id');
});

it('RF-27 (S-55): la apertura la crea el admin al crear el empleado y no afecta la caja', function () {
    actuarComo(Rol::Admin);
    $id = $this->postJson('/api/empleados', ['nombre' => 'Lamus', 'documento' => '1001', 'saldo_apertura' => 200000, 'fecha_apertura' => '2026-10-01'])
        ->assertCreated()->assertJsonPath('saldo', '200000.00')->json('id');

    $apertura = MovimientoEmpleado::where('empleado_id', $id)->sole();
    expect($apertura->apertura)->toBeTrue()
        ->and($apertura->fecha->toDateString())->toBe('2026-10-01')
        ->and($apertura->tipo->value)->toBe('prestamo');

    foreach (['2026-10-01', '2026-11-20'] as $dia) {
        $r = empResumenCaja($dia);
        expect($r['prestamos_empleados'])->toBe('0.00')->and($r['efectivo']['prestamos_empleados'])->toBe('0.00');
    }

    // Una sola vez, al crear.
    $this->putJson("/api/empleados/{$id}", ['saldo_apertura' => 5000])->assertUnprocessable()->assertJsonValidationErrors('saldo_apertura');
    $this->postJson('/api/empleados', ['nombre' => 'Futuro', 'saldo_apertura' => 1000, 'fecha_apertura' => '2026-11-21'])->assertUnprocessable();
});

it('RF-27: anular un préstamo y un abono: el saldo vuelve y el movimiento sigue visible', function () {
    $e = empCrear();
    actuarComo(Rol::Mostrador);
    $p1 = empPrestar($e, 100000)->json('id');
    $p2 = empPrestar($e, 30000)->json('id');
    $a = empAbonar($e, 20000)->json('id');

    $admin = actuarComo(Rol::Admin);
    $this->postJson("/api/empleados/movimientos/{$p2}/anulaciones", ['motivo' => 'Valor duplicado'])
        ->assertCreated()->assertJsonPath('saldo', '80000.00')->assertJsonPath('movimiento.anulacion.motivo', 'Valor duplicado');
    $this->postJson("/api/empleados/movimientos/{$a}/anulaciones", ['motivo' => 'No era de él'])
        ->assertCreated()->assertJsonPath('saldo', '100000.00');
    $this->postJson("/api/empleados/movimientos/{$a}/anulaciones", ['motivo' => 'Otra vez'])->assertUnprocessable();
    $this->postJson("/api/empleados/movimientos/{$p1}/anulaciones", [])->assertUnprocessable()->assertJsonValidationErrors('motivo');

    $estado = $this->getJson("/api/empleados/{$e->id}/estado-cuenta")->assertOk()->assertJsonPath('saldo', '100000.00');
    $filas = collect($estado->json('movimientos'));
    expect($filas->pluck('tipo')->all())->toBe(['prestamo', 'prestamo', 'abono', 'anulacion', 'anulacion'])
        ->and($filas->pluck('saldo')->all())->toBe(['100000.00', '130000.00', '110000.00', '80000.00', '100000.00'])
        ->and($filas->firstWhere('movimiento_id', $p2)['anulado'])->toBeTrue()
        ->and($filas->firstWhere('movimiento_id', $p2)['anulacion']['usuario'])->toBe($admin->name)
        ->and($filas->firstWhere('movimiento_id', $p1)['anulado'])->toBeFalse();
    expect(MovimientoEmpleado::count())->toBe(3);
});

it('RF-27 (S-57): anular un préstamo que dejaría el saldo negativo se rechaza', function () {
    $e = empCrear();
    actuarComo(Rol::Mostrador);
    $p = empPrestar($e, 50000)->json('id');
    empAbonar($e, 30000)->assertCreated();

    actuarComo(Rol::Admin);
    $this->postJson("/api/empleados/movimientos/{$p}/anulaciones", ['motivo' => 'Error'])
        ->assertUnprocessable()->assertJsonPath('saldo_resultante', '-30000.00');
});

it('RF-27 / regla 8: préstamos y abonos no tienen rutas para editar ni borrar', function () {
    $e = empCrear();
    actuarComo(Rol::Mostrador);
    $p = empPrestar($e, 1000)->json('id');

    actuarComo(Rol::Admin);
    expect($this->deleteJson("/api/empleados/movimientos/{$p}")->status())->toBeIn([404, 405]);
    expect($this->putJson("/api/empleados/movimientos/{$p}", ['valor' => 5])->status())->toBeIn([404, 405]);
    $this->deleteJson("/api/empleados/{$e->id}")->assertStatus(405);
    expect(MovimientoEmpleado::findOrFail($p)->valor)->toBe('1000.00');
});

it('RF-27: sin rutas DELETE en la cuenta de empleados', function () {
    $conDelete = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_contains($r->uri(), 'empleados') && in_array('DELETE', $r->methods(), true));

    expect($conDelete)->toBeEmpty();
});

it('RF-27: no se desactiva un empleado con saldo; en $0 sí, y queda auditado', function () {
    $e = empCrear('Jhonnier', ['saldo_apertura' => 15000]);

    actuarComo(Rol::Admin);
    $r = $this->putJson("/api/empleados/{$e->id}", ['activo' => false])->assertUnprocessable();
    expect($r->json('message'))->toContain('Jhonnier')->toContain('$15.000');
    expect($e->fresh()->activo)->toBeTrue();

    actuarComo(Rol::Mostrador);
    empAbonar($e, 15000, 'nomina')->assertCreated();

    actuarComo(Rol::Admin);
    $this->putJson("/api/empleados/{$e->id}", ['activo' => false, 'nombre' => 'Jhonnier R.'])->assertOk()->assertJsonPath('activo', false);
    $this->assertDatabaseHas('auditoria', ['tabla' => 'empleados', 'registro_id' => $e->id, 'campo' => 'activo', 'valor_nuevo' => '0']);
    $this->assertDatabaseHas('auditoria', ['tabla' => 'empleados', 'registro_id' => $e->id, 'campo' => 'nombre', 'valor_nuevo' => 'Jhonnier R.']);

    // Inactivo: no recibe préstamos.
    actuarComo(Rol::Mostrador);
    empPrestar($e->fresh(), 1000)->assertUnprocessable();
});

it('RF-27 (S-57, S-81): el mostrador elige empleados con su saldo, pero no ve resumen ni estado de cuenta', function () {
    empCrear('Jhon', ['saldo_apertura' => 5000]);
    empCrear('Inactivo')->update(['activo' => false]);
    $e = Empleado::where('nombre', 'Jhon')->sole();
    actuarComo(Rol::Mostrador);

    $lista = $this->getJson('/api/empleados')->assertOk()->json('data');
    expect(collect($lista)->pluck('nombre')->all())->toBe(['Jhon'])
        ->and(array_keys($lista[0]))->toBe(['id', 'nombre', 'documento', 'saldo'])
        ->and($lista[0]['saldo'])->toBe('5000.00');

    $this->getJson('/api/empleados/resumen')->assertForbidden();
    $this->getJson("/api/empleados/{$e->id}/estado-cuenta")->assertForbidden();
    $this->postJson('/api/empleados', ['nombre' => 'Nuevo'])->assertForbidden();
    $this->putJson("/api/empleados/{$e->id}", ['nombre' => 'Otro'])->assertForbidden();
    $p = empPrestar($e, 1000)->assertCreated()->json('id');
    $this->postJson("/api/empleados/movimientos/{$p}/anulaciones", ['motivo' => 'Error'])->assertForbidden();
});

it('RF-27: logística recibe 403 en toda la cuenta de empleados', function () {
    $e = empCrear();
    actuarComo(Rol::Logistica);

    $this->getJson('/api/empleados')->assertForbidden();
    $this->getJson('/api/empleados/resumen')->assertForbidden();
    $this->getJson("/api/empleados/{$e->id}/estado-cuenta")->assertForbidden();
    $this->postJson('/api/empleados', ['nombre' => 'X'])->assertForbidden();
    empPrestar($e, 1000)->assertForbidden();
    empAbonar($e, 1000)->assertForbidden();
});

it('RF-27: el admin crea, edita y anula, pero no registra préstamos ni abonos', function () {
    $e = empCrear();
    actuarComo(Rol::Admin);

    empPrestar($e, 1000)->assertForbidden();
    empAbonar($e, 1000, 'nomina')->assertForbidden();
    $this->putJson("/api/empleados/{$e->id}", ['documento' => '777'])->assertOk()->assertJsonPath('documento', '777');
    $this->postJson('/api/empleados', ['nombre' => 'Jhon'])->assertUnprocessable()->assertJsonValidationErrors('nombre');
    $this->getJson('/api/empleados')->assertOk();
});

it('RF-27 (S-55): el resumen del día refleja préstamos y abonos en efectivo; la anulación resta el día en que se hace', function () {
    $e = empCrear('Jhon', ['saldo_apertura' => 500000]);
    actuarComo(Rol::Mostrador);
    empPrestar($e, 100000)->assertCreated();
    $efectivo = empAbonar($e, 30000)->json('id');
    empAbonar($e, 20000, 'caja', cuentaBanco()->id)->assertCreated();
    empAbonar($e, 10000, 'nomina')->assertCreated();

    $r = empResumenCaja();
    expect($r['prestamos_empleados'])->toBe('100000.00')
        ->and($r['abonos_empleados'])->toBe('50000.00')
        ->and($r['efectivo']['prestamos_empleados'])->toBe('100000.00')
        ->and($r['efectivo']['abonos_empleados'])->toBe('30000.00')
        ->and($r['efectivo_neto_dia'])->toBe('-70000.00')
        ->and($r['total_ingreso_dia'])->toBe('0.00'); // no es venta ni cuenta para la meta
    $banco = collect($r['cuentas'])->firstWhere('cuenta_pago_id', cuentaBanco()->id);
    expect($banco['abonos_empleados'])->toBe('20000.00')->and($banco['neto'])->toBe('20000.00');

    // Al día siguiente el admin anula el abono en efectivo: resta en la caja del 21, no del 20.
    Carbon::setTestNow('2026-11-21 15:00:00');
    actuarComo(Rol::Admin);
    $this->postJson("/api/empleados/movimientos/{$efectivo}/anulaciones", ['motivo' => 'Billete falso'])->assertCreated();

    expect(empResumenCaja('2026-11-20')['efectivo_neto_dia'])->toBe('-70000.00');
    $r21 = empResumenCaja('2026-11-21');
    expect($r21['efectivo']['abonos_empleados'])->toBe('-30000.00')->and($r21['efectivo_neto_dia'])->toBe('-30000.00');
});

it('RF-27 (S-55): con la caja de hoy cerrada no entran préstamos ni abonos por caja; por nómina sí', function () {
    $e = empCrear('Jhon', ['saldo_apertura' => 50000]);
    abrirCaja('2026-11-20', 0);
    actuarComo(Rol::Mostrador);
    $this->postJson('/api/caja/cierres', ['fecha' => '2026-11-20', 'efectivo_contado' => 0])->assertCreated();

    empPrestar($e, 1000)->assertUnprocessable()->assertJsonPath('caja_cerrada', '2026-11-20');
    empAbonar($e, 1000)->assertUnprocessable()->assertJsonPath('caja_cerrada', '2026-11-20');
    empAbonar($e, 1000, 'nomina')->assertCreated();
});

it('RF-27: el resumen da saldo por empleado, total pendiente y último movimiento, activos primero', function () {
    $a = empCrear('Zuly', ['saldo_apertura' => 10000, 'fecha_apertura' => '2026-11-01']);
    $b = empCrear('Andrés');
    $c = empCrear('Beto', ['saldo_apertura' => 5000]);
    actuarComo(Rol::Mostrador);
    empPrestar($b, 7000)->assertCreated();
    empAbonar($c, 5000, 'nomina')->assertCreated();

    actuarComo(Rol::Admin);
    $this->putJson("/api/empleados/{$c->id}", ['activo' => false])->assertOk();

    $r = $this->getJson('/api/empleados/resumen')->assertOk();
    expect(collect($r->json('data'))->pluck('nombre')->all())->toBe(['Andrés', 'Zuly', 'Beto'])
        ->and($r->json('totales.total_pendiente'))->toBe('17000.00')
        ->and($r->json('totales.empleados_con_saldo'))->toBe(2)
        ->and(collect($r->json('data'))->firstWhere('id', $a->id)['ultimo_movimiento'])->toBe('2026-11-01')
        ->and(collect($r->json('data'))->firstWhere('id', $b->id)['saldo'])->toBe('7000.00');

    expect(collect($this->getJson('/api/empleados/resumen?q=zu')->json('data'))->pluck('id')->all())->toBe([$a->id]);
});

it('RF-27: la cuenta de empleados no entra en la cartera de clientes', function () {
    $e = empCrear('Jhon', ['saldo_apertura' => 90000]);
    actuarComo(Rol::Admin);

    $cartera = $this->getJson('/api/cartera?solo_con_saldo=0')->assertOk()->json();
    expect(json_encode($cartera))->not->toContain('Jhon');
    expect(User::where('name', 'Jhon')->exists())->toBeFalse();
    expect($e->getAttributes())->not->toHaveKey('user_id');
});
