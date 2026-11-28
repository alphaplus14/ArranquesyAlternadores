<?php

use App\Enums\Rol;
use App\Models\Auditoria;
use App\Models\CierreCaja;
use App\Models\Configuracion;
use App\Models\Empleado;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * S-98 (RF-25, RF-26; ajusta S-49, S-53 y S-84): base fija de caja de $ 135.000. Siempre queda en el
 * cajón y no cuenta para el cuadre: el mostrador cuenta todo el cajón, el sistema resta la base y
 * compara el resto con el esperado; el arrastre al día siguiente es max(contado − base, 0). Faltar la
 * base solo avisa (exige observación); un movimiento que usaría la base devuelve aviso_base.
 */

beforeEach(function () {
    sembrarDemo();
    Carbon::setTestNow('2026-11-26 15:00:00'); // 10:00 en Bogotá
    config(['caja.base' => 135000]);
});

afterEach(fn () => Carbon::setTestNow());

function s98Cerrar(string $fecha, $contado, ?string $observacion = null)
{
    return test()->postJson('/api/caja/cierres', ['fecha' => $fecha, 'efectivo_contado' => $contado, ...($observacion !== null ? ['observacion' => $observacion] : [])]);
}

function s98Dia(string $fecha): array
{
    return test()->getJson("/api/caja/dias/{$fecha}")->assertOk()->json('resumen');
}

it('S-98: contar 785.000 con base 135.000 y esperado 650.000 da diferencia 0 y guarda la base', function () {
    actuarComo(Rol::Mostrador);
    abrirCaja('2026-11-26', 650000);

    expect(s98Dia('2026-11-26'))->toMatchArray([
        'efectivo_esperado' => '650000.00', 'base_caja' => '135000.00', 'efectivo_en_cajon_esperado' => '785000.00',
    ]);

    s98Cerrar('2026-11-26', 785000)->assertCreated()
        ->assertJsonPath('base', '135000.00')
        ->assertJsonPath('efectivo_contado', '785000.00')
        ->assertJsonPath('efectivo_para_cuadre', '650000.00')
        ->assertJsonPath('efectivo_esperado', '650000.00')
        ->assertJsonPath('diferencia', '0.00');
    expect(CierreCaja::sole()->base)->toBe('135000.00');
});

it('S-98: sobrante y faltante se calculan sin la base', function () {
    actuarComo(Rol::Mostrador);
    abrirCaja('2026-11-25', 650000);
    s98Cerrar('2026-11-25', 800000)->assertCreated()->assertJsonPath('diferencia', '15000.00');
    abrirCaja('2026-11-26', 650000);
    s98Cerrar('2026-11-26', 770000)->assertCreated()->assertJsonPath('diferencia', '-15000.00');
});

it('S-98: contado menor que la base exige observación (no bloquea) y el arrastre queda en 0', function () {
    actuarComo(Rol::Mostrador);
    abrirCaja('2026-11-25', 650000);

    s98Cerrar('2026-11-25', 100000)->assertUnprocessable()
        ->assertJsonPath('errors.observacion.0', 'Falta la base de caja: escriba la observación.');
    s98Cerrar('2026-11-25', 100000, 'Se llevaron la base para cambio')->assertCreated()
        ->assertJsonPath('efectivo_para_cuadre', '-35000.00')
        ->assertJsonPath('diferencia', '-685000.00');

    expect(s98Dia('2026-11-26')['apertura'])->toMatchArray(['valor' => '0.00', 'origen' => 'arrastre', 'cierre_anterior_valor' => '0.00']);
});

it('S-98: el arrastre del día siguiente es contado − base, también tras reabrir y volver a cerrar', function () {
    actuarComo(Rol::Mostrador);
    abrirCaja('2026-11-25', 650000);
    $cierre = s98Cerrar('2026-11-25', 785000)->assertCreated()->json('id');

    expect(s98Dia('2026-11-26'))->toMatchArray(['efectivo_esperado' => '650000.00', 'efectivo_en_cajon_esperado' => '785000.00'])
        ->and(s98Dia('2026-11-26')['apertura'])->toMatchArray(['valor' => '650000.00', 'cierre_anterior_valor' => '650000.00']);

    actuarComo(Rol::Admin);
    $this->postJson("/api/caja/cierres/{$cierre}/reaperturas", ['motivo' => 'Recontar'])->assertCreated();
    actuarComo(Rol::Mostrador);
    s98Cerrar('2026-11-25', 835000)->assertCreated()->assertJsonPath('diferencia', '50000.00');

    expect(s98Dia('2026-11-26')['apertura']['valor'])->toBe('700000.00');
});

it('S-98: un cierre anterior con base 0 arrastra su contado completo y se ve igual', function () {
    actuarComo(Rol::Mostrador);
    abrirCaja('2026-11-25', 600000);
    s98Cerrar('2026-11-25', 785000)->assertCreated();
    // Como quedó un cierre anterior a S-98 (la migración deja base 0).
    DB::table('cierres_caja')->update(['base' => 0, 'diferencia' => 185000]);

    expect(s98Dia('2026-11-26')['apertura']['valor'])->toBe('785000.00');
    actuarComo(Rol::Admin);
    $fila = collect($this->getJson('/api/caja/meses/2026-11')->assertOk()->json('dias'))->firstWhere('fecha', '2026-11-25');
    expect($fila)->toMatchArray(['efectivo_contado' => '785000.00', 'base' => '0.00', 'efectivo_para_cuadre' => '785000.00', 'diferencia' => '185000.00']);
});

it('S-98: cambiar la base de la configuración no altera los cierres existentes', function () {
    actuarComo(Rol::Mostrador);
    abrirCaja('2026-11-25', 650000);
    s98Cerrar('2026-11-25', 785000)->assertCreated();

    actuarComo(Rol::Admin);
    $this->putJson('/api/configuracion/base-caja', ['valor' => 200000])->assertOk()->assertJsonPath('valor', '200000.00');

    $cierre = CierreCaja::sole();
    expect($cierre->base)->toBe('135000.00')->and($cierre->diferencia)->toBe('0.00');
    expect(s98Dia('2026-11-26'))->toMatchArray(['base_caja' => '200000.00', 'efectivo_en_cajon_esperado' => '850000.00'])
        ->and(s98Dia('2026-11-26')['apertura']['valor'])->toBe('650000.00')
        // El día cerrado muestra la base con que se cerró.
        ->and(s98Dia('2026-11-25')['base_caja'])->toBe('135000.00');
});

it('S-98: solo el admin cambia la base y queda auditada; el mostrador la lee', function () {
    $admin = actuarComo(Rol::Admin);
    $this->getJson('/api/configuracion/base-caja')->assertOk()->assertJsonPath('valor', '135000.00');
    $this->putJson('/api/configuracion/base-caja', ['valor' => 150000])->assertOk();
    $this->putJson('/api/configuracion/base-caja', ['valor' => -1])->assertUnprocessable();

    $id = Configuracion::where('clave', Configuracion::BASE_CAJA)->value('id');
    $this->assertDatabaseHas('auditoria', ['tabla' => 'configuraciones', 'registro_id' => $id, 'campo' => 'valor', 'valor_anterior' => '135000', 'valor_nuevo' => '150000', 'usuario_id' => $admin->id]);

    actuarComo(Rol::Mostrador);
    $this->getJson('/api/configuracion/base-caja')->assertOk()->assertJsonPath('valor', '150000.00');
    $this->putJson('/api/configuracion/base-caja', ['valor' => 1])->assertForbidden();
    actuarComo(Rol::Logistica);
    $this->getJson('/api/configuracion/base-caja')->assertForbidden();
});

it('S-98: un movimiento que deja el esperado por debajo de 0 devuelve aviso_base sin bloquear', function () {
    actuarComo(Rol::Mostrador);
    abrirCaja('2026-11-26', 100000);

    movimientoCaja('CAJA GRAL', 50000)->assertCreated()->assertJsonPath('aviso_base', false)->assertJsonPath('valor', '50000.00');
    $gasto = categoriaGasto();
    movimientoCaja($gasto, 120000)->assertCreated()->assertJsonPath('aviso_base', false);
    movimientoCaja($gasto, 40000)->assertCreated()->assertJsonPath('aviso_base', true);
    expect(s98Dia('2026-11-26'))->toMatchArray(['efectivo_esperado' => '-10000.00', 'efectivo_en_cajon_esperado' => '125000.00']);
});

it('S-98: un préstamo a empleado que usaría la base también avisa', function () {
    actuarComo(Rol::Admin);
    $id = $this->postJson('/api/empleados', ['nombre' => 'Jhon'])->assertCreated()->json('id');
    actuarComo(Rol::Mostrador);
    abrirCaja('2026-11-26', 20000);

    $this->postJson("/api/empleados/{$id}/prestamos", ['valor' => 10000])->assertCreated()->assertJsonPath('aviso_base', false);
    $this->postJson("/api/empleados/{$id}/prestamos", ['valor' => 30000])->assertCreated()->assertJsonPath('aviso_base', true)
        ->assertJsonPath('empleado_id', $id);
    expect(Empleado::find($id))->not->toBeNull();
});

it('S-98: el resumen del día y el del mes no suman la base a ingresos ni al esperado', function () {
    actuarComo(Rol::Mostrador);
    abrirCaja('2026-11-26', 650000);
    movimientoCaja('CAJA GRAL', 50000)->assertCreated();

    $dia = s98Dia('2026-11-26');
    expect($dia)->toMatchArray(['total_ingreso_dia' => '0.00', 'efectivo_neto_dia' => '50000.00', 'efectivo_esperado' => '700000.00', 'efectivo_en_cajon_esperado' => '835000.00']);

    actuarComo(Rol::Admin);
    $mes = $this->getJson('/api/caja/meses/2026-11')->assertOk()->json();
    $fila = collect($mes['dias'])->firstWhere('fecha', '2026-11-26');
    expect($fila)->toMatchArray(['efectivo_esperado' => '700000.00', 'base' => '135000.00', 'total_ingreso_dia' => '0.00'])
        ->and($mes['totales']['efectivo_neto_dia'])->toBe('50000.00');
});

/** Una categoría de gasto en efectivo de la semilla. */
function categoriaGasto(): string
{
    return DB::table('categorias_caja')->where('grupo', 'vario')->where('activa', true)->value('nombre');
}
