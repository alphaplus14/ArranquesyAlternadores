<?php

use App\Enums\Rol;
use App\Models\AperturaCaja;
use Illuminate\Support\Carbon;

/*
 * S-100 (RF-25, S-49, S-84): el motivo de la apertura (3 a 200 caracteres) es obligatorio solo si
 * (a) el valor difiere del arrastre del último cierre o (b) se modifica un cambio manual ya guardado
 * (incluido volver al arrastre). El primer día sin cierres sigue opcional; igual al arrastre sin cambio
 * previo no crea fila ni pide motivo.
 */

beforeEach(function () {
    sembrarDemo();
    Carbon::setTestNow('2026-11-26 15:00:00');
    actuarComo(Rol::Mostrador);
});

afterEach(fn () => Carbon::setTestNow());

const S100_MENSAJE = 'Escriba el motivo del cambio de apertura (mínimo 3 caracteres).';

function s100Apertura(string $fecha, $valor, ?string $motivo = null)
{
    return test()->putJson("/api/caja/dias/{$fecha}/apertura", ['valor' => $valor, ...($motivo !== null ? ['motivo' => $motivo] : [])]);
}

/** Cierre del 25/11 con 650.000 (base 0 en pruebas): el 26 arrastra 650.000. */
function s100ConArrastre(): void
{
    abrirCaja('2026-11-25', 650000);
    test()->postJson('/api/caja/cierres', ['fecha' => '2026-11-25', 'efectivo_contado' => 650000])->assertCreated();
}

it('S-100: distinto del arrastre exige motivo de 3 a 200 caracteres', function () {
    s100ConArrastre();

    s100Apertura('2026-11-26', 600000)->assertUnprocessable()->assertJsonPath('errors.motivo.0', S100_MENSAJE);
    s100Apertura('2026-11-26', 600000, '  ab ')->assertUnprocessable()->assertJsonPath('errors.motivo.0', S100_MENSAJE);
    s100Apertura('2026-11-26', 600000, str_repeat('x', 201))->assertUnprocessable()->assertJsonValidationErrors('motivo');
    s100Apertura('2026-11-26', 600000, 'Ret')->assertOk()->assertJsonPath('apertura.origen', 'manual')->assertJsonPath('apertura.motivo', 'Ret');
});

it('S-100: el primer día sin cierres el motivo es opcional', function () {
    s100Apertura('2026-11-26', 50000)->assertOk()->assertJsonPath('apertura.valor', '50000.00');
    // Cambiarla el mismo primer día tampoco lo exige (no hay arrastre contra qué comparar).
    s100Apertura('2026-11-26', 60000)->assertOk()->assertJsonPath('apertura.valor', '60000.00');
});

it('S-100: igual al arrastre sin cambio previo responde 200 sin crear fila ni pedir motivo', function () {
    s100ConArrastre();

    s100Apertura('2026-11-26', 650000)->assertOk()->assertJsonPath('apertura.origen', 'arrastre');
    expect(AperturaCaja::where('fecha', '2026-11-26')->exists())->toBeFalse();
});

it('S-100: con un cambio manual guardado, modificarlo exige motivo; volver al arrastre también, y queda auditado', function () {
    s100ConArrastre();
    s100Apertura('2026-11-26', 600000, 'Se retiró dinero')->assertOk();

    s100Apertura('2026-11-26', 620000)->assertUnprocessable()->assertJsonPath('errors.motivo.0', S100_MENSAJE);
    s100Apertura('2026-11-26', 650000)->assertUnprocessable()->assertJsonPath('errors.motivo.0', S100_MENSAJE);
    s100Apertura('2026-11-26', 650000, 'Se devolvió el dinero')->assertOk()
        ->assertJsonPath('apertura.valor', '650000.00')
        ->assertJsonPath('apertura.motivo', 'Se devolvió el dinero');

    $fila = AperturaCaja::where('fecha', '2026-11-26')->sole();
    $this->assertDatabaseHas('auditoria', ['tabla' => 'aperturas_caja', 'registro_id' => $fila->id, 'campo' => 'valor', 'valor_anterior' => '600000.00', 'valor_nuevo' => '650000.00']);
    $this->assertDatabaseHas('auditoria', ['tabla' => 'aperturas_caja', 'registro_id' => $fila->id, 'campo' => 'motivo', 'valor_nuevo' => 'Se devolvió el dinero']);
});

it('S-100: un día cerrado no cambia la apertura', function () {
    s100ConArrastre();
    s100Apertura('2026-11-25', 1, 'Cambio tardío')->assertUnprocessable()->assertJsonPath('campo', 'fecha');
});

it('S-100: solo el mostrador cambia la apertura', function (Rol $rol) {
    actuarComo($rol);
    s100Apertura('2026-11-26', 50000, 'Prueba de rol')->assertForbidden();
})->with([
    'admin' => [Rol::Admin],
    'contable' => [Rol::Contable],
    'logística' => [Rol::Logistica],
]);
