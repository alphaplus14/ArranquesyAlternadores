<?php

use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\CuentaPago;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
 * RF-25 (S-49 a S-51, S-53, S-54): apertura diaria de la caja, resumen del día, cierre con arqueo
 * (efectivo esperado = apertura + neto del día), reapertura y cuadre mensual.
 */

beforeEach(function () {
    sembrarDemo();
    referencia('RN-4410')->update(['precio_venta' => 50000]);
    ingresar(referencia('RN-4410'), 10);
    Carbon::setTestNow('2026-11-20 15:00:00'); // 10:00 en Bogotá
});

function nequi(): CuentaPago
{
    return CuentaPago::where('nombre', 'Nequi')->firstOrFail();
}

/** Venta de contado de N unidades de RN-4410 (50.000 c/u) cerrada con esos pagos, como un mostrador. Devuelve [ventaId, itemIds]. */
function ventaContado(int $unidades, array $pagos): array
{
    $anterior = auth()->user();
    actuarComo(Rol::Mostrador);
    $id = abrirVenta();
    $items = [];
    for ($i = 0; $i < $unidades; $i++) {
        $items[] = test()->postJson("/api/ventas/{$id}/escanear", ['codigo_barras' => unidadDe('RN-4410')->codigo_barras, 'metodo_captura' => 'escaneado'])->assertCreated()->json('venta_item_id');
    }
    cerrarVenta($id, $pagos)->assertOk();
    if ($anterior) {
        test()->actingAs($anterior);
    }

    return [$id, $items];
}

function pago(CuentaPago $cuenta, $valor): array
{
    return ['cuenta_pago_id' => $cuenta->id, 'valor' => $valor];
}

/** PUT de la apertura del día como el usuario en sesión. */
function apertura(string $fecha, $valor)
{
    return test()->putJson("/api/caja/dias/{$fecha}/apertura", ['valor' => $valor]);
}

/** Cierre del día como el usuario en sesión. */
function cerrarDia(string $fecha, $contado, ?string $observacion = null)
{
    return test()->postJson('/api/caja/cierres', ['fecha' => $fecha, 'efectivo_contado' => $contado, 'observacion' => $observacion]);
}

/** Arma el día del ejemplo (S-49): apertura 100.000 y un efectivo neto de 105.000. Devuelve el mostrador en sesión. */
function diaDeEjemplo()
{
    abrirCaja('2026-11-20', 100000);

    // Ventas de contado: 100.000 en efectivo y 50.000 divididos (30.000 efectivo + 20.000 Nequi).
    [$venta1, $items1] = ventaContado(2, [pago(cuentaEfectivo(), 100000)]);
    ventaContado(1, [pago(cuentaEfectivo(), 30000), pago(nequi(), 20000)]);
    // Crédito de 70.000: cuenta para el total del día, no para el efectivo.
    ventaCredito(Cliente::factory()->create(), [70000]);

    $mostrador = actuarComo(Rol::Mostrador);
    // Devolución de una unidad de la primera venta (S-50): sale del efectivo, no se digita como gasto.
    test()->postJson("/api/ventas/{$venta1}/reversos", ['tipo' => 'devolucion', 'motivo' => 'Pieza equivocada', 'venta_item_id' => $items1[0]])->assertOk();
    // Recaudo de cartera en efectivo (S-51: no cuenta para la meta).
    abonar(Cliente::factory()->create(), 40000)->assertCreated();
    // Movimientos de caja.
    movimientoCaja('EMPRESA', 10000)->assertCreated();
    $anulado = movimientoCaja('PERSONAL', 9999)->json('id');
    test()->postJson("/api/caja/movimientos/{$anulado}/anulaciones", ['motivo' => 'Duplicado'])->assertCreated();
    movimientoCaja('SERVICIO DE TORNO', 15000)->assertCreated();
    movimientoCaja('MANTENIMIENTO DE ARRANQUE', 8000, nequi())->assertCreated();
    // Efectivo que llega durante el día desde la Caja General: ingreso externo, no apertura.
    movimientoCaja('CAJA GRAL', 5000)->assertCreated();

    // Pago a proveedor en efectivo (admin).
    actuarComo(Rol::Admin);
    test()->postJson('/api/proveedores/'.referencia('RN-4410')->proveedor_id.'/pagos', ['valor' => 25000, 'cuenta_pago_id' => cuentaEfectivo()->id])->assertCreated();

    test()->actingAs($mostrador);

    return $mostrador;
}

/* ───────────────────────── Apertura diaria (S-49) ───────────────────────── */

it('RF-25 / S-49: el mostrador registra la apertura de hoy y la corrige antes de cerrar, con auditoría', function () {
    $mostrador = actuarComo(Rol::Mostrador);

    apertura('2026-11-20', 50000)->assertOk()
        ->assertJsonPath('apertura.valor', '50000.00')
        ->assertJsonPath('apertura.fijado_por.id', $mostrador->id);
    apertura('2026-11-20', 60000)->assertOk()->assertJsonPath('apertura.valor', '60000.00');

    $this->getJson('/api/caja/dias/2026-11-20')->assertOk()
        ->assertJsonPath('resumen.apertura.valor', '60000.00')
        ->assertJsonPath('resumen.apertura_editable', true);
    $this->assertDatabaseHas('auditoria', [
        'tabla' => 'aperturas_caja', 'campo' => 'valor', 'valor_anterior' => '50000.00', 'valor_nuevo' => '60000.00', 'usuario_id' => $mostrador->id,
    ]);
    expect(DB::table('aperturas_caja')->where('fecha', '2026-11-20')->count())->toBe(1);
});

it('RF-25 / S-49: con el día cerrado la apertura ya no cambia', function () {
    actuarComo(Rol::Mostrador);
    apertura('2026-11-20', 0)->assertOk();
    cerrarDia('2026-11-20', 0)->assertCreated();

    apertura('2026-11-20', 1000)->assertUnprocessable()
        ->assertJsonPath('message', 'El día ya está cerrado: no se puede cambiar la apertura.');
    $this->getJson('/api/caja/dias/2026-11-20')->assertJsonPath('resumen.apertura_editable', false)
        ->assertJsonPath('resumen.apertura.valor', '0.00');
});

it('RF-25 / S-49: la apertura rechaza fecha futura, valor negativo, más de 2 decimales y valor vacío', function () {
    actuarComo(Rol::Mostrador);

    apertura('2026-11-21', 1000)->assertUnprocessable()->assertJsonValidationErrors('fecha');
    apertura('2026-11-20', -1)->assertUnprocessable()->assertJsonValidationErrors('valor');
    apertura('2026-11-20', '10.555')->assertUnprocessable()->assertJsonValidationErrors('valor');
    $this->putJson('/api/caja/dias/2026-11-20/apertura', [])->assertUnprocessable()->assertJsonValidationErrors('valor');
    apertura('2026-11-19', 1000)->assertOk(); // un día anterior sin cierre sí
});

it('RF-25 / S-54: el admin y logística no registran la apertura', function (Rol $rol) {
    actuarComo($rol);

    apertura('2026-11-20', 1000)->assertForbidden();
    expect(DB::table('aperturas_caja')->count())->toBe(0);
})->with([Rol::Admin, Rol::Logistica]);

it('RF-25 / S-49: sin apertura el día no se cierra; con apertura 0 sí', function () {
    actuarComo(Rol::Mostrador);

    $this->getJson('/api/caja/dias/2026-11-20')->assertJsonPath('resumen.apertura', null)
        ->assertJsonPath('resumen.efectivo_esperado', null);
    cerrarDia('2026-11-20', 0)->assertUnprocessable()->assertJsonPath('campo', 'apertura');

    apertura('2026-11-20', 0)->assertOk();
    cerrarDia('2026-11-20', 0)->assertCreated()
        ->assertJsonPath('apertura', '0.00')
        ->assertJsonPath('efectivo_esperado', '0.00')
        ->assertJsonPath('diferencia', '0.00');
});

it('RF-25 / S-49: la sugerencia de apertura es el efectivo contado del último cierre vigente anterior', function () {
    actuarComo(Rol::Mostrador);
    $this->getJson('/api/caja/dias/2026-11-20')->assertJsonPath('resumen.apertura_sugerida', null);

    apertura('2026-11-18', 0)->assertOk();
    cerrarDia('2026-11-18', 0)->assertCreated();
    apertura('2026-11-19', 0)->assertOk();
    $c19 = cerrarDia('2026-11-19', 12345)->assertCreated()->json('id');

    $this->getJson('/api/caja/dias/2026-11-20')->assertJsonPath('resumen.apertura_sugerida', '12345.00');

    // Reabierto el 19, la sugerencia sale del cierre vigente anterior (el 18).
    actuarComo(Rol::Admin);
    $this->postJson("/api/caja/cierres/{$c19}/reaperturas", ['motivo' => 'Recontar'])->assertCreated();
    $this->getJson('/api/caja/dias/2026-11-20')->assertJsonPath('resumen.apertura_sugerida', '0.00');
});

/* ───────────────────────── Resumen del día y cierre ───────────────────────── */

it('RF-25 / S-49 a S-51: el resumen suma ventas, crédito, devolución, recaudo, pago a proveedor, egresos y otros ingresos; esperado = apertura + neto', function () {
    diaDeEjemplo();

    $r = $this->getJson('/api/caja/dias/2026-11-20')->assertOk();

    expect($r->json('resumen'))->toMatchArray([
        'ventas_contado' => '150000.00',
        'ventas_credito' => '70000.00',
        'devoluciones' => '50000.00',
        'recaudo_cartera' => '40000.00',
        'pagos_proveedores' => '25000.00',
        'otros_ingresos' => '23000.00',
        'ingresos_externos' => '5000.00',
        // 150.000 + 70.000 + 23.000; el recaudo y el ingreso externo no cuentan para la meta.
        'total_ingreso_dia' => '243000.00',
        // 130.000 + 40.000 + 15.000 + 5.000 − 50.000 − 25.000 − 10.000
        'efectivo_neto_dia' => '105000.00',
        // apertura 100.000 + neto 105.000
        'efectivo_esperado' => '205000.00',
        'meta_venta_diaria' => '10900000.00',
        'faltante_meta' => '10657000.00',
    ])->and($r->json('resumen.apertura.valor'))->toBe('100000.00')
        ->and($r->json('resumen'))->not->toHaveKeys(['saldo_anterior', 'saldo_acumulado', 'saldo_inicial'])
        ->and($r->json('resumen.egresos'))->toBe(['vario' => '10000.00', 'fijo' => '0.00', 'financiero' => '0.00', 'total' => '10000.00'])
        ->and($r->json('resumen.efectivo.ventas'))->toBe('130000.00');

    $porCuenta = collect($r->json('resumen.cuentas'))->keyBy('nombre');
    expect($porCuenta['Nequi'])->toMatchArray(['ventas' => '20000.00', 'otros_ingresos' => '8000.00', 'neto' => '28000.00'])
        ->and($porCuenta['Efectivo']['neto'])->toBe('105000.00')
        ->and($r->json('movimientos'))->toHaveCount(5)
        ->and($r->json('cierre'))->toBeNull();
});

it('RF-25 / S-49: el cierre guarda apertura, esperado = apertura + neto y el arqueo; queda en solo lectura con quién y cuándo', function () {
    $mostrador = diaDeEjemplo();

    cerrarDia('2026-11-20', 204000, 'Faltaron mil')->assertCreated()
        ->assertJsonPath('apertura', '100000.00')
        ->assertJsonPath('efectivo_esperado', '205000.00')
        ->assertJsonPath('efectivo_contado', '204000.00')
        ->assertJsonPath('diferencia', '-1000.00')
        // Columnas viejas: en los cierres nuevos repiten apertura y esperado.
        ->assertJsonPath('saldo_anterior', '100000.00')
        ->assertJsonPath('saldo_acumulado', '205000.00')
        ->assertJsonPath('usuario.id', $mostrador->id);

    $this->getJson('/api/caja/dias/2026-11-20')->assertJsonPath('cierre.observacion', 'Faltaron mil')
        ->assertJsonPath('cierre.usuario.name', $mostrador->name);
});

it('RF-25 / S-49: días con aperturas distintas dan cierres independientes (sin saldo encadenado)', function () {
    actuarComo(Rol::Mostrador);
    apertura('2026-11-19', 50000)->assertOk();
    movimientoCaja('EMPRESA', 10000, extra: ['fecha' => '2026-11-19'])->assertCreated();
    cerrarDia('2026-11-19', 40000)->assertCreated()->assertJsonPath('efectivo_esperado', '40000.00')->assertJsonPath('diferencia', '0.00');

    // El 20 abre con otro valor: lo del 19 no se arrastra.
    apertura('2026-11-20', 70000)->assertOk();
    cerrarDia('2026-11-20', 70500)->assertCreated()
        ->assertJsonPath('apertura', '70000.00')
        ->assertJsonPath('efectivo_esperado', '70000.00')
        ->assertJsonPath('diferencia', '500.00');
});

it('RF-25: un segundo cierre del mismo día, un día futuro o un efectivo negativo se rechazan', function () {
    abrirCaja('2026-11-20', 0);
    actuarComo(Rol::Mostrador);

    cerrarDia('2026-11-21', 0)->assertUnprocessable()->assertJsonPath('message', 'No se puede cerrar un día futuro.');
    cerrarDia('2026-11-20', -1)->assertUnprocessable()->assertJsonValidationErrors('efectivo_contado');
    cerrarDia('2026-11-20', 0)->assertCreated();
    cerrarDia('2026-11-20', 0)->assertUnprocessable()->assertJsonPath('cierre_id', fn ($id) => is_int($id));
});

it('RF-25: no se cierra el día con ventas abiertas con ítems', function () {
    abrirCaja('2026-11-20', 0);
    actuarComo(Rol::Mostrador);
    $abierta = abrirVenta();
    $this->postJson("/api/ventas/{$abierta}/escanear", ['codigo_barras' => unidadDe('RN-4410')->codigo_barras, 'metodo_captura' => 'escaneado'])->assertCreated();

    $this->getJson('/api/caja/dias/2026-11-20')->assertJsonPath('ventas_abiertas.0.id', $abierta);
    cerrarDia('2026-11-20', 0)->assertUnprocessable()->assertJsonPath('ventas_abiertas', [$abierta]);

    cerrarVenta($abierta)->assertOk();
    cerrarDia('2026-11-20', 50000)->assertCreated()->assertJsonPath('diferencia', '0.00');
});

it('RF-25 / S-53: con la caja de hoy cerrada no se cierran ventas ni se registran abonos, pagos o devoluciones', function () {
    abrirCaja('2026-11-20', 0);
    [$venta, $items] = ventaContado(1, [pago(cuentaEfectivo(), 50000)]);
    actuarComo(Rol::Mostrador);
    $abierta = abrirVenta();
    cerrarDia('2026-11-20', 50000)->assertCreated();

    // Escanear sí (no mueve dinero); cerrar no.
    $this->postJson("/api/ventas/{$abierta}/escanear", ['codigo_barras' => unidadDe('RN-4410')->codigo_barras, 'metodo_captura' => 'escaneado'])->assertCreated();
    cerrarVenta($abierta)->assertUnprocessable()->assertJsonPath('caja_cerrada', '2026-11-20');
    abonar(Cliente::factory()->create(), 1000)->assertUnprocessable();
    $this->postJson("/api/ventas/{$venta}/reversos", ['tipo' => 'devolucion', 'motivo' => 'Tarde', 'venta_item_id' => $items[0]])->assertUnprocessable();
    actuarComo(Rol::Admin);
    $this->postJson('/api/proveedores/'.referencia('RN-4410')->proveedor_id.'/pagos', ['valor' => 100, 'cuenta_pago_id' => cuentaEfectivo()->id])->assertUnprocessable();
});

it('RF-25 / S-53: el admin reabre con motivo (la única escritura del admin) y el mostrador corrige la apertura y vuelve a cerrar', function () {
    $mostrador = diaDeEjemplo();
    $cierre = cerrarDia('2026-11-20', 204000)->assertCreated()->json('id');

    $this->postJson("/api/caja/cierres/{$cierre}/reaperturas", ['motivo' => 'Recontar'])->assertForbidden();

    actuarComo(Rol::Admin);
    $this->postJson("/api/caja/cierres/{$cierre}/reaperturas", [])->assertUnprocessable()->assertJsonValidationErrors('motivo');
    $this->postJson("/api/caja/cierres/{$cierre}/reaperturas", ['motivo' => 'La apertura era otra'])->assertCreated()
        ->assertJsonPath('reapertura.motivo', 'La apertura era otra');
    $this->postJson("/api/caja/cierres/{$cierre}/reaperturas", ['motivo' => 'Otra vez'])->assertUnprocessable();

    test()->actingAs($mostrador);
    apertura('2026-11-20', 99000)->assertOk();
    movimientoCaja('ENCOMIENDAS', 5000)->assertCreated();
    cerrarDia('2026-11-20', 199000)->assertCreated()
        ->assertJsonPath('efectivo_esperado', '199000.00') // 99.000 + 100.000
        ->assertJsonPath('diferencia', '0.00');

    $dia = $this->getJson('/api/caja/dias/2026-11-20')->assertOk();
    expect($dia->json('cierre.efectivo_contado'))->toBe('199000.00')
        ->and($dia->json('cierres_reabiertos.0.reapertura.motivo'))->toBe('La apertura era otra')
        ->and($dia->json('cierres_reabiertos.0.apertura'))->toBe('100000.00');
});

it('RF-25 / S-53: los cierres se reabren del más reciente hacia atrás', function () {
    abrirCaja('2026-11-19', 0);
    abrirCaja('2026-11-20', 0);
    actuarComo(Rol::Mostrador);
    $c19 = cerrarDia('2026-11-19', 0)->assertCreated()->json('id');
    $c20 = cerrarDia('2026-11-20', 0)->assertCreated()->json('id');

    actuarComo(Rol::Admin);
    $this->postJson("/api/caja/cierres/{$c19}/reaperturas", ['motivo' => 'Revisar'])->assertUnprocessable();
    $this->postJson("/api/caja/cierres/{$c20}/reaperturas", ['motivo' => 'Revisar'])->assertCreated();
    $this->postJson("/api/caja/cierres/{$c19}/reaperturas", ['motivo' => 'Revisar'])->assertCreated();
});

it('RF-25 / S-48: una venta de contado sin detalle de pago y un abono viejo sin cuenta cuentan en efectivo', function () {
    [$venta] = ventaContado(1, [pago(nequi(), 50000)]);
    DB::table('venta_pagos')->where('venta_id', $venta)->delete(); // como las ventas anteriores a RF-24
    $cliente = Cliente::factory()->create();
    $usuario = actuarComo(Rol::Mostrador);
    $base = ['cliente_id' => $cliente->id, 'fecha' => '2026-11-20', 'valor' => 1000, 'usuario_id' => $usuario->id, 'created_at' => now()];
    DB::table('abonos_clientes')->insert([['medio' => 'efectivo', ...$base], ['medio' => 'transferencia', ...$base]]);

    $r = $this->getJson('/api/caja/dias/2026-11-20')->assertOk();
    $porCuenta = collect($r->json('resumen.cuentas'))->keyBy('nombre');

    expect($r->json('resumen.ventas_sin_detalle'))->toBe('50000.00')
        ->and($porCuenta['Efectivo']['ventas'])->toBe('50000.00')
        ->and($porCuenta['Efectivo']['recaudo'])->toBe('1000.00')
        ->and($porCuenta['Sin cuenta (registros anteriores)']['recaudo'])->toBe('1000.00')
        ->and($r->json('resumen.efectivo_neto_dia'))->toBe('51000.00');
});

it('RF-25 / S-49: una anulación de abono resta en el día en que se anula; el día siguiente no arrastra nada', function () {
    $cliente = Cliente::factory()->create();
    actuarComo(Rol::Mostrador);
    $abono = abonar($cliente, 30000)->json('abono.id');

    Carbon::setTestNow('2026-11-21 15:00:00');
    actuarComo(Rol::Admin);
    $this->postJson("/api/abonos/{$abono}/anulaciones", ['motivo' => 'Cheque devuelto'])->assertCreated();

    $this->getJson('/api/caja/dias/2026-11-20')->assertJsonPath('resumen.recaudo_cartera', '30000.00');
    $this->getJson('/api/caja/dias/2026-11-21')->assertJsonPath('resumen.recaudo_cartera', '-30000.00')
        ->assertJsonPath('resumen.efectivo_neto_dia', '-30000.00')
        ->assertJsonPath('resumen.efectivo_esperado', null);
});

/* ───────────────────────── Cierres anteriores y cuadre mensual ───────────────────────── */

it('RF-25 / S-49: los cierres anteriores a la apertura diaria (con saldo encadenado) se siguen leyendo', function () {
    $usuario = User::factory()->rol(Rol::Mostrador)->create();
    DB::table('cierres_caja')->insert([
        'fecha' => '2026-11-19', 'apertura' => null, 'efectivo_contado' => 300000, 'efectivo_esperado' => 50000, 'diferencia' => 250000,
        'saldo_anterior' => 250000, 'saldo_acumulado' => 300000, 'observacion' => 'Cierre viejo', 'usuario_id' => $usuario->id, 'cerrado_at' => '2026-11-19 23:00:00',
    ]);

    actuarComo(Rol::Admin);
    $this->getJson('/api/caja/dias/2026-11-19')->assertOk()
        ->assertJsonPath('cierre.apertura', null)
        ->assertJsonPath('cierre.saldo_anterior', '250000.00')
        ->assertJsonPath('cierre.saldo_acumulado', '300000.00')
        ->assertJsonPath('cierre.efectivo_esperado', '50000.00');

    $fila = collect($this->getJson('/api/caja/meses/2026-11')->assertOk()->json('dias'))->firstWhere('fecha', '2026-11-19');
    expect($fila)->toMatchArray(['cerrado' => true, 'apertura' => null, 'efectivo_esperado' => '50000.00', 'efectivo_contado' => '300000.00', 'diferencia' => '250000.00']);

    // Y sugiere abrir el 20 con lo contado en ese cierre.
    actuarComo(Rol::Mostrador);
    $this->getJson('/api/caja/dias/2026-11-20')->assertJsonPath('resumen.apertura_sugerida', '300000.00');
});

it('RF-25: el cuadre mensual trae apertura, esperado, contado y diferencia por día, marca los sin cerrar y acumula el neto desde 0', function () {
    $mostrador = diaDeEjemplo();
    cerrarDia('2026-11-20', 204000, 'Faltaron mil')->assertCreated();

    Carbon::setTestNow('2026-11-21 15:00:00');
    test()->actingAs($mostrador);
    apertura('2026-11-21', 150000)->assertOk();
    movimientoCaja('Plan celulares', 20000)->assertCreated();

    actuarComo(Rol::Admin);
    $r = $this->getJson('/api/caja/meses/2026-11')->assertOk();
    $dias = collect($r->json('dias'))->keyBy('fecha');

    expect($dias)->toHaveCount(21)
        ->and($r->json())->not->toHaveKey('saldo_anterior')
        ->and($dias['2026-11-19'])->toMatchArray(['cerrado' => false, 'apertura' => null, 'efectivo_neto_dia' => '0.00', 'efectivo_esperado' => null, 'neto_acumulado_mes' => '0.00'])
        ->and($dias['2026-11-20'])->toMatchArray([
            'cerrado' => true,
            'apertura' => '100000.00',
            'ventas_efectivo' => '130000.00',
            'ventas_billetera' => '20000.00',
            'ventas_credito' => '70000.00',
            'otros_ingresos' => '23000.00',
            'total_ingreso_dia' => '243000.00',
            'egresos' => '10000.00',
            'efectivo_neto_dia' => '105000.00',
            'efectivo_esperado' => '205000.00',
            'efectivo_contado' => '204000.00',
            'diferencia' => '-1000.00',
            'neto_acumulado_mes' => '105000.00',
            'faltante_meta' => '10657000.00',
            'observacion' => 'Faltaron mil',
        ])
        ->and($dias['2026-11-20'])->not->toHaveKeys(['saldo_acumulado', 'arqueo'])
        ->and($dias['2026-11-21'])->toMatchArray([
            'cerrado' => false, 'apertura' => '150000.00', 'egresos' => '20000.00', 'efectivo_neto_dia' => '-20000.00',
            'efectivo_esperado' => '130000.00', 'efectivo_contado' => null, 'diferencia' => null, 'neto_acumulado_mes' => '85000.00',
        ])
        ->and($r->json('totales.total_ingreso_dia'))->toBe('243000.00')
        ->and($r->json('totales.egresos'))->toBe('30000.00')
        ->and($r->json('totales.efectivo_neto_dia'))->toBe('85000.00')
        ->and($r->json('totales.neto_acumulado_mes'))->toBe('85000.00')
        ->and($r->json('totales.diferencia'))->toBe('-1000.00')
        ->and($r->json('totales.dias_cerrados'))->toBe(1);

    actuarComo(Rol::Mostrador);
    $this->getJson('/api/caja/meses/2026-11')->assertForbidden();
});

it('RF-25 / S-51: la meta diaria sigue en Configuración, solo admin; ya no existe el saldo inicial único', function () {
    actuarComo(Rol::Admin);
    $this->getJson('/api/configuracion/meta-venta')->assertOk()->assertJsonPath('valor', '10900000.00');
    $this->putJson('/api/configuracion/meta-venta', ['valor' => 12000000])->assertOk()->assertJsonPath('valor', '12000000.00');
    $this->assertDatabaseHas('auditoria', ['tabla' => 'configuraciones', 'campo' => 'valor', 'valor_nuevo' => '12000000']);

    $rutas = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri());
    expect($rutas->filter(fn ($uri) => str_contains($uri, 'saldo-caja-inicial'))->all())->toBe([]);
    $this->getJson('/api/configuracion/saldo-caja-inicial')->assertNotFound();
    $this->putJson('/api/configuracion/saldo-caja-inicial', ['valor' => 1, 'fecha' => '2026-11-01'])->assertNotFound();

    actuarComo(Rol::Mostrador);
    $this->getJson('/api/caja/dias/2026-11-20')->assertJsonPath('resumen.meta_venta_diaria', '12000000.00');
    $this->putJson('/api/configuracion/meta-venta', ['valor' => 1])->assertForbidden();
});
