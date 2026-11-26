<?php

use App\Enums\Rol;
use App\Models\AperturaCaja;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * S-84 (RF-25, RF-26; ajusta S-49 y S-53): la apertura de un día es el efectivo contado del último
 * cierre vigente anterior (arrastre, en vivo). Cambiarla a mano es la excepción (mostrador, con motivo
 * si difiere). Sin cierres previos ni apertura (primer día) no se cierra.
 */

beforeEach(function () {
    sembrarDemo();
    Carbon::setTestNow('2026-11-26 15:00:00');
});

function s84Apertura(string $fecha, $valor, ?string $motivo = null)
{
    return test()->putJson("/api/caja/dias/{$fecha}/apertura", ['valor' => $valor, ...($motivo !== null ? ['motivo' => $motivo] : [])]);
}

function s84Cerrar(string $fecha, $contado)
{
    return test()->postJson('/api/caja/cierres', ['fecha' => $fecha, 'efectivo_contado' => $contado]);
}

function s84Dia(string $fecha): array
{
    return test()->getJson("/api/caja/dias/{$fecha}")->assertOk()->json('resumen');
}

it('S-84: sin cierre previo ni apertura, la apertura es null y el día no se cierra', function () {
    actuarComo(Rol::Mostrador);

    expect(s84Dia('2026-11-26'))->toMatchArray(['apertura' => null, 'efectivo_esperado' => null]);
    s84Cerrar('2026-11-26', 0)->assertUnprocessable()
        ->assertJsonPath('message', 'Registre la apertura de la caja (primer día sin cierres anteriores).');

    // Primer día: se abre a mano sin motivo obligatorio.
    s84Apertura('2026-11-26', 50000)->assertOk()->assertJsonPath('apertura.origen', 'manual');
    s84Cerrar('2026-11-26', 50000)->assertCreated()->assertJsonPath('apertura', '50000.00');
});

it('S-84: con un cierre de $ 650.000, el día siguiente abre solo con esa apertura y se cierra sin registrar nada', function () {
    actuarComo(Rol::Mostrador);
    abrirCaja('2026-11-25', 600000);
    movimientoCaja('CAJA GRAL', 50000, extra: ['fecha' => '2026-11-25'])->assertCreated();
    s84Cerrar('2026-11-25', 650000)->assertCreated();

    $r = s84Dia('2026-11-26');
    expect($r['apertura'])->toMatchArray([
        'valor' => '650000.00', 'origen' => 'arrastre', 'desde_cierre' => '2026-11-25', 'cierre_anterior_valor' => '650000.00',
        'motivo' => null, 'hay_dias_sin_cerrar' => false,
    ])->and($r['efectivo_esperado'])->toBe('650000.00');

    s84Cerrar('2026-11-26', 650000)->assertCreated()
        ->assertJsonPath('apertura', '650000.00')->assertJsonPath('diferencia', '0.00');
    expect(AperturaCaja::where('fecha', '2026-11-26')->exists())->toBeFalse();
    expect(s84Dia('2026-11-26')['apertura'])->toMatchArray(['valor' => '650000.00', 'origen' => 'cierre']);
});

it('S-84: cambiar la apertura: distinta sin motivo 422; con motivo queda manual con auditoría; igual al arrastre no crea fila', function () {
    $mostrador = actuarComo(Rol::Mostrador);
    abrirCaja('2026-11-25', 650000);
    s84Cerrar('2026-11-25', 650000)->assertCreated();

    // Igual al arrastre y sin cambio previo: OK sin motivo y sin fila nueva.
    s84Apertura('2026-11-26', 650000)->assertOk()->assertJsonPath('apertura.origen', 'arrastre');
    expect(AperturaCaja::where('fecha', '2026-11-26')->exists())->toBeFalse();

    s84Apertura('2026-11-26', 600000)->assertUnprocessable()->assertJsonPath('campo', 'motivo');
    s84Apertura('2026-11-26', 600000, 'Se retiró dinero de la caja')->assertOk()
        ->assertJsonPath('apertura.valor', '600000.00')
        ->assertJsonPath('apertura.origen', 'manual')
        ->assertJsonPath('apertura.motivo', 'Se retiró dinero de la caja')
        ->assertJsonPath('apertura.cierre_anterior_valor', '650000.00')
        ->assertJsonPath('apertura.fijado_por.id', $mostrador->id);

    s84Apertura('2026-11-26', 590000, 'Faltaba un billete')->assertOk();
    $this->assertDatabaseHas('auditoria', ['tabla' => 'aperturas_caja', 'campo' => 'valor', 'valor_anterior' => '600000.00', 'valor_nuevo' => '590000.00', 'usuario_id' => $mostrador->id]);
    $this->assertDatabaseHas('auditoria', ['tabla' => 'aperturas_caja', 'campo' => 'motivo', 'valor_anterior' => 'Se retiró dinero de la caja', 'valor_nuevo' => 'Faltaba un billete']);

    s84Apertura('2026-11-26', 590000, str_repeat('x', 201))->assertUnprocessable()->assertJsonValidationErrors('motivo');
});

it('S-84: en un día cerrado la apertura no se cambia', function () {
    actuarComo(Rol::Mostrador);
    abrirCaja('2026-11-26', 0);
    s84Cerrar('2026-11-26', 0)->assertCreated();

    s84Apertura('2026-11-26', 1000, 'Otro valor')->assertUnprocessable()
        ->assertJsonPath('message', 'El día ya está cerrado: no se puede cambiar la apertura.');
    expect(s84Dia('2026-11-26')['apertura_editable'])->toBeFalse();
});

it('S-84: reabrir y recerrar el día anterior con otro contado actualiza la apertura de un día sin cerrar, no la de uno cerrado', function () {
    $mostrador = actuarComo(Rol::Mostrador);
    abrirCaja('2026-11-23', 100000);
    $c23 = s84Cerrar('2026-11-23', 100000)->assertCreated()->json('id');
    s84Cerrar('2026-11-24', 100000)->assertCreated(); // abre solo con 100.000 (arrastre)
    expect(s84Dia('2026-11-25')['apertura']['valor'])->toBe('100000.00');

    // El 25 (sin cerrar) arrastra del 24; reabrir y recerrar el 24 con otro contado lo cambia.
    actuarComo(Rol::Admin);
    $c24 = DB::table('cierres_caja')->where('fecha', '2026-11-24')->value('id');
    $this->postJson("/api/caja/cierres/{$c24}/reaperturas", ['motivo' => 'Recontar'])->assertCreated();
    test()->actingAs($mostrador);
    s84Cerrar('2026-11-24', 120000)->assertCreated();
    expect(s84Dia('2026-11-25')['apertura']['valor'])->toBe('120000.00');

    // El 24 ya cerrado conserva la apertura guardada en su cierre aunque cambie el 23.
    expect(s84Dia('2026-11-24')['apertura'])->toMatchArray(['valor' => '100000.00', 'origen' => 'cierre']);
    expect($c23)->toBeInt();
});

it('S-84: avisa si hay días con movimientos sin cerrar entre el último cierre y el día; un día sin actividad no cuenta', function () {
    actuarComo(Rol::Mostrador);
    abrirCaja('2026-11-22', 0);
    s84Cerrar('2026-11-22', 0)->assertCreated();

    // El 23 no tuvo actividad (como un domingo): no avisa.
    expect(s84Dia('2026-11-24')['apertura']['hay_dias_sin_cerrar'])->toBeFalse();

    movimientoCaja('CAJA GRAL', 5000, extra: ['fecha' => '2026-11-24'])->assertCreated();
    expect(s84Dia('2026-11-26')['apertura'])->toMatchArray(['desde_cierre' => '2026-11-22', 'hay_dias_sin_cerrar' => true]);
});

it('S-84: el cuadre mensual arrastra en cadena y marca el origen, con un día manual en medio', function () {
    actuarComo(Rol::Mostrador);
    abrirCaja('2026-11-20', 10000);
    s84Cerrar('2026-11-20', 10000)->assertCreated();
    s84Cerrar('2026-11-21', 12000)->assertCreated(); // arrastre 10.000
    s84Apertura('2026-11-22', 5000, 'Se llevó efectivo al banco')->assertOk();
    s84Cerrar('2026-11-22', 5000)->assertCreated(); // manual
    s84Cerrar('2026-11-23', 5000)->assertCreated(); // arrastre 5.000

    actuarComo(Rol::Admin);
    $dias = collect($this->getJson('/api/caja/meses/2026-11')->assertOk()->json('dias'))->keyBy('fecha');
    expect($dias['2026-11-21'])->toMatchArray(['apertura' => '10000.00', 'apertura_origen' => 'arrastre', 'diferencia' => '2000.00'])
        ->and($dias['2026-11-22'])->toMatchArray(['apertura' => '5000.00', 'apertura_origen' => 'manual'])
        ->and($dias['2026-11-23'])->toMatchArray(['apertura' => '5000.00', 'apertura_origen' => 'arrastre'])
        // Sin cerrar: arrastra del último cierre (el 23).
        ->and($dias['2026-11-24'])->toMatchArray(['apertura' => '5000.00', 'apertura_origen' => 'arrastre', 'cerrado' => false])
        ->and($dias['2026-11-26'])->toMatchArray(['apertura' => '5000.00', 'apertura_origen' => 'arrastre', 'efectivo_esperado' => '5000.00'])
        // Antes del primer cierre no hay apertura.
        ->and($dias['2026-11-19'])->toMatchArray(['apertura' => null, 'apertura_origen' => null]);
});

it('S-84: el cuadre mensual usa un número fijo de consultas para la apertura, sin importar cuántos días haya', function () {
    actuarComo(Rol::Mostrador);
    abrirCaja('2026-11-02', 1000);
    s84Cerrar('2026-11-02', 1000)->assertCreated();
    actuarComo(Rol::Admin);

    $contar = function (string $mes): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->getJson("/api/caja/meses/{$mes}")->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    // Octubre (31 días, sin cierres) y noviembre (26 días hasta hoy) hacen las mismas consultas.
    expect($contar('2026-10'))->toBe($contar('2026-11'));
});

it('S-84 / S-54: solo el mostrador cambia la apertura; admin y logística no', function (Rol $rol) {
    actuarComo($rol);
    s84Apertura('2026-11-26', 1000, 'Prueba')->assertForbidden();
    expect(AperturaCaja::count())->toBe(0);
})->with([Rol::Admin, Rol::Logistica]);

it('S-84: una apertura registrada antes de S-84 (sin motivo) igual a la del cierre anterior se muestra como arrastre', function () {
    actuarComo(Rol::Mostrador);
    abrirCaja('2026-11-25', 650000);
    s84Cerrar('2026-11-25', 650000)->assertCreated();
    $mostrador = \App\Models\User::where('rol', Rol::Mostrador)->firstOrFail();
    AperturaCaja::create(['fecha' => '2026-11-26', 'valor' => 650000, 'usuario_id' => $mostrador->id]);

    expect(s84Dia('2026-11-26')['apertura'])->toMatchArray(['valor' => '650000.00', 'origen' => 'arrastre']);
    actuarComo(Rol::Admin);
    $fila = collect($this->getJson('/api/caja/meses/2026-11')->json('dias'))->firstWhere('fecha', '2026-11-26');
    expect($fila['apertura_origen'])->toBe('arrastre');
});
