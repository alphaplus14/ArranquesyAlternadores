<?php

use App\Enums\Rol;
use App\Models\AperturaCaja;
use Illuminate\Support\Carbon;

/*
 * S-84 (RF-25, S-49): el motivo del cambio manual de la apertura (3 a 200 caracteres) es SIEMPRE
 * obligatorio: con el valor distinto o igual al automático, con o sin cambio previo y también el primer
 * día sin cierres. Reemplaza la condición de S-100 (decisión del usuario, 28-nov-2026).
 */

beforeEach(function () {
    sembrarDemo();
    Carbon::setTestNow('2026-11-26 15:00:00');
    actuarComo(Rol::Mostrador);
});

afterEach(fn () => Carbon::setTestNow());

const MENSAJE_MOTIVO_APERTURA = 'Escriba el motivo del cambio de la apertura (mínimo 3 caracteres).';

function aperturaConMotivo(string $fecha, $valor, ?string $motivo = null)
{
    return test()->putJson("/api/caja/dias/{$fecha}/apertura", ['valor' => $valor, ...($motivo !== null ? ['motivo' => $motivo] : [])]);
}

/** Cierre del 25/11 con 650.000 (base 0 en pruebas): el 26 arrastra 650.000. */
function cierreConArrastre(): void
{
    abrirCaja('2026-11-25', 650000);
    test()->postJson('/api/caja/cierres', ['fecha' => '2026-11-25', 'efectivo_contado' => 650000])->assertCreated();
}

it('S-84: distinto del arrastre exige motivo de 3 a 200 caracteres', function () {
    cierreConArrastre();

    aperturaConMotivo('2026-11-26', 600000)->assertUnprocessable()->assertJsonPath('errors.motivo.0', MENSAJE_MOTIVO_APERTURA);
    aperturaConMotivo('2026-11-26', 600000, '  ab ')->assertUnprocessable()->assertJsonPath('errors.motivo.0', MENSAJE_MOTIVO_APERTURA);
    aperturaConMotivo('2026-11-26', 600000, str_repeat('x', 201))->assertUnprocessable()->assertJsonValidationErrors('motivo');
    aperturaConMotivo('2026-11-26', 600000, 'Ret')->assertOk()->assertJsonPath('apertura.origen', 'manual')->assertJsonPath('apertura.motivo', 'Ret');
});

it('S-84: igual al arrastre también exige motivo, y con motivo queda como cambio manual', function () {
    cierreConArrastre();

    aperturaConMotivo('2026-11-26', 650000)->assertUnprocessable()->assertJsonPath('errors.motivo.0', MENSAJE_MOTIVO_APERTURA);
    expect(AperturaCaja::where('fecha', '2026-11-26')->exists())->toBeFalse();

    aperturaConMotivo('2026-11-26', 650000, 'Confirmo el valor')->assertOk()->assertJsonPath('apertura.origen', 'manual');
});

it('S-84: el primer día sin cierres también exige motivo', function () {
    aperturaConMotivo('2026-11-26', 50000)->assertUnprocessable()->assertJsonPath('errors.motivo.0', MENSAJE_MOTIVO_APERTURA);
    aperturaConMotivo('2026-11-26', 50000, 'Primer día')->assertOk()->assertJsonPath('apertura.valor', '50000.00');
    // Cambiarla otra vez sin motivo tampoco pasa.
    aperturaConMotivo('2026-11-26', 60000)->assertUnprocessable()->assertJsonPath('errors.motivo.0', MENSAJE_MOTIVO_APERTURA);
});

it('S-84: con un cambio manual guardado, modificarlo exige motivo y queda auditado', function () {
    cierreConArrastre();
    aperturaConMotivo('2026-11-26', 600000, 'Se retiró dinero')->assertOk();

    aperturaConMotivo('2026-11-26', 650000)->assertUnprocessable()->assertJsonPath('errors.motivo.0', MENSAJE_MOTIVO_APERTURA);
    aperturaConMotivo('2026-11-26', 650000, 'Se devolvió el dinero')->assertOk()
        ->assertJsonPath('apertura.valor', '650000.00')
        ->assertJsonPath('apertura.motivo', 'Se devolvió el dinero');

    $fila = AperturaCaja::where('fecha', '2026-11-26')->sole();
    $this->assertDatabaseHas('auditoria', ['tabla' => 'aperturas_caja', 'registro_id' => $fila->id, 'campo' => 'valor', 'valor_anterior' => '600000.00', 'valor_nuevo' => '650000.00']);
    $this->assertDatabaseHas('auditoria', ['tabla' => 'aperturas_caja', 'registro_id' => $fila->id, 'campo' => 'motivo', 'valor_nuevo' => 'Se devolvió el dinero']);
});

it('S-84: un día cerrado no cambia la apertura', function () {
    cierreConArrastre();
    aperturaConMotivo('2026-11-25', 1, 'Cambio tardío')->assertUnprocessable()->assertJsonPath('campo', 'fecha');
});

it('S-84: solo el mostrador cambia la apertura', function (Rol $rol) {
    actuarComo($rol);
    aperturaConMotivo('2026-11-26', 50000, 'Prueba de rol')->assertForbidden();
})->with([
    'admin' => [Rol::Admin],
    'contable' => [Rol::Contable],
    'logística' => [Rol::Logistica],
]);
