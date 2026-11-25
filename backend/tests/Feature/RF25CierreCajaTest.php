<?php

use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\CuentaPago;
use App\Services\CajaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * RF-25 (S-49 a S-51, S-53, S-54): resumen del día, cierre con arqueo, reapertura y cuadre mensual.
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

/** Arma el día del ejemplo (S-49) y devuelve el mostrador en sesión. */
function diaDeEjemplo()
{
    app(CajaService::class)->fijarSaldoInicial('100000', '2026-11-01');

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
    movimientoCaja('CAJA GRAL', 5000)->assertCreated();

    // Pago a proveedor en efectivo (admin).
    actuarComo(Rol::Admin);
    test()->postJson('/api/proveedores/'.referencia('RN-4410')->proveedor_id.'/pagos', ['valor' => 25000, 'cuenta_pago_id' => cuentaEfectivo()->id])->assertCreated();

    test()->actingAs($mostrador);

    return $mostrador;
}

it('RF-25 / S-49 a S-51: el resumen del día suma ventas, crédito, devolución, recaudo, pago a proveedor, egresos y otros ingresos', function () {
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
        'saldo_anterior' => '100000.00',
        'saldo_acumulado' => '205000.00',
        'meta_venta_diaria' => '10900000.00',
        'faltante_meta' => '10657000.00',
    ])->and($r->json('resumen.egresos'))->toBe(['vario' => '10000.00', 'fijo' => '0.00', 'financiero' => '0.00', 'total' => '10000.00'])
        ->and($r->json('resumen.efectivo.ventas'))->toBe('130000.00');

    $porCuenta = collect($r->json('resumen.cuentas'))->keyBy('nombre');
    expect($porCuenta['Nequi'])->toMatchArray(['ventas' => '20000.00', 'otros_ingresos' => '8000.00', 'neto' => '28000.00'])
        ->and($porCuenta['Efectivo']['neto'])->toBe('105000.00')
        ->and($r->json('movimientos'))->toHaveCount(5)
        ->and($r->json('cierre'))->toBeNull();
});

it('RF-25 / S-49: el cierre guarda efectivo esperado, arqueo y saldos; queda en solo lectura con quién y cuándo', function () {
    $mostrador = diaDeEjemplo();

    $this->postJson('/api/caja/cierres', ['fecha' => '2026-11-20', 'efectivo_contado' => 104000, 'observacion' => 'Faltaron mil'])->assertCreated()
        ->assertJsonPath('efectivo_esperado', '105000.00')
        ->assertJsonPath('diferencia', '-1000.00')
        ->assertJsonPath('saldo_anterior', '100000.00')
        ->assertJsonPath('saldo_acumulado', '205000.00')
        ->assertJsonPath('usuario.id', $mostrador->id);

    $this->getJson('/api/caja/dias/2026-11-20')->assertJsonPath('cierre.observacion', 'Faltaron mil')
        ->assertJsonPath('cierre.usuario.name', $mostrador->name);
});

it('RF-25: un segundo cierre del mismo día, un día futuro o sin saldo inicial se rechazan', function () {
    actuarComo(Rol::Mostrador);
    $this->postJson('/api/caja/cierres', ['fecha' => '2026-11-20', 'efectivo_contado' => 0])->assertUnprocessable()
        ->assertJsonPath('message', 'Falta el saldo inicial de caja: el administrador debe fijarlo en Configuración antes del primer cierre.');

    app(CajaService::class)->fijarSaldoInicial('0', '2026-11-01');
    $this->postJson('/api/caja/cierres', ['fecha' => '2026-11-21', 'efectivo_contado' => 0])->assertUnprocessable()
        ->assertJsonPath('message', 'No se puede cerrar un día futuro.');
    $this->postJson('/api/caja/cierres', ['fecha' => '2026-11-20', 'efectivo_contado' => -1])->assertUnprocessable()->assertJsonValidationErrors('efectivo_contado');
    $this->postJson('/api/caja/cierres', ['fecha' => '2026-11-20', 'efectivo_contado' => 0])->assertCreated();
    $this->postJson('/api/caja/cierres', ['fecha' => '2026-11-20', 'efectivo_contado' => 0])->assertUnprocessable()
        ->assertJsonPath('cierre_id', fn ($id) => is_int($id));
});

it('RF-25: no se cierra el día con ventas abiertas con ítems', function () {
    app(CajaService::class)->fijarSaldoInicial('0', '2026-11-01');
    actuarComo(Rol::Mostrador);
    $abierta = abrirVenta();
    $this->postJson("/api/ventas/{$abierta}/escanear", ['codigo_barras' => unidadDe('RN-4410')->codigo_barras, 'metodo_captura' => 'escaneado'])->assertCreated();

    $this->getJson('/api/caja/dias/2026-11-20')->assertJsonPath('ventas_abiertas.0.id', $abierta);
    $this->postJson('/api/caja/cierres', ['fecha' => '2026-11-20', 'efectivo_contado' => 0])->assertUnprocessable()
        ->assertJsonPath('ventas_abiertas', [$abierta]);

    cerrarVenta($abierta)->assertOk();
    $this->postJson('/api/caja/cierres', ['fecha' => '2026-11-20', 'efectivo_contado' => 50000])->assertCreated()
        ->assertJsonPath('diferencia', '0.00');
});

it('RF-25 / S-53: con la caja de hoy cerrada no se cierran ventas ni se registran abonos, pagos o devoluciones', function () {
    app(CajaService::class)->fijarSaldoInicial('0', '2026-11-01');
    [$venta, $items] = ventaContado(1, [pago(cuentaEfectivo(), 50000)]);
    actuarComo(Rol::Mostrador);
    $abierta = abrirVenta();
    $this->postJson('/api/caja/cierres', ['fecha' => '2026-11-20', 'efectivo_contado' => 50000])->assertCreated();

    // Escanear sí (no mueve dinero); cerrar no.
    $this->postJson("/api/ventas/{$abierta}/escanear", ['codigo_barras' => unidadDe('RN-4410')->codigo_barras, 'metodo_captura' => 'escaneado'])->assertCreated();
    cerrarVenta($abierta)->assertUnprocessable()->assertJsonPath('caja_cerrada', '2026-11-20');
    abonar(Cliente::factory()->create(), 1000)->assertUnprocessable();
    $this->postJson("/api/ventas/{$venta}/reversos", ['tipo' => 'devolucion', 'motivo' => 'Tarde', 'venta_item_id' => $items[0]])->assertUnprocessable();
    actuarComo(Rol::Admin);
    $this->postJson('/api/proveedores/'.referencia('RN-4410')->proveedor_id.'/pagos', ['valor' => 100, 'cuenta_pago_id' => cuentaEfectivo()->id])->assertUnprocessable();
});

it('RF-25 / S-53: el admin reabre con motivo (la única escritura del admin) y el mostrador vuelve a cerrar', function () {
    $mostrador = diaDeEjemplo();
    $cierre = $this->postJson('/api/caja/cierres', ['fecha' => '2026-11-20', 'efectivo_contado' => 104000])->assertCreated()->json('id');

    $this->postJson("/api/caja/cierres/{$cierre}/reaperturas", ['motivo' => 'Recontar'])->assertForbidden();

    actuarComo(Rol::Admin);
    $this->postJson("/api/caja/cierres/{$cierre}/reaperturas", [])->assertUnprocessable()->assertJsonValidationErrors('motivo');
    $this->postJson("/api/caja/cierres/{$cierre}/reaperturas", ['motivo' => 'Faltó un gasto'])->assertCreated()
        ->assertJsonPath('reapertura.motivo', 'Faltó un gasto');
    $this->postJson("/api/caja/cierres/{$cierre}/reaperturas", ['motivo' => 'Otra vez'])->assertUnprocessable();

    test()->actingAs($mostrador);
    movimientoCaja('ENCOMIENDAS', 5000)->assertCreated();
    $this->postJson('/api/caja/cierres', ['fecha' => '2026-11-20', 'efectivo_contado' => 100000])->assertCreated()
        ->assertJsonPath('efectivo_esperado', '100000.00')
        ->assertJsonPath('diferencia', '0.00');

    $dia = $this->getJson('/api/caja/dias/2026-11-20')->assertOk();
    expect($dia->json('cierre.efectivo_contado'))->toBe('100000.00')
        ->and($dia->json('cierres_reabiertos.0.reapertura.motivo'))->toBe('Faltó un gasto');
});

it('RF-25 / S-53: los cierres se reabren del más reciente hacia atrás', function () {
    app(CajaService::class)->fijarSaldoInicial('0', '2026-11-01');
    actuarComo(Rol::Mostrador);
    $c19 = $this->postJson('/api/caja/cierres', ['fecha' => '2026-11-19', 'efectivo_contado' => 0])->assertCreated()->json('id');
    $c20 = $this->postJson('/api/caja/cierres', ['fecha' => '2026-11-20', 'efectivo_contado' => 0])->assertCreated()->json('id');

    actuarComo(Rol::Admin);
    $this->postJson("/api/caja/cierres/{$c19}/reaperturas", ['motivo' => 'Revisar'])->assertUnprocessable();
    $this->postJson("/api/caja/cierres/{$c20}/reaperturas", ['motivo' => 'Revisar'])->assertCreated();
    $this->postJson("/api/caja/cierres/{$c19}/reaperturas", ['motivo' => 'Revisar'])->assertCreated();
});

it('RF-25 / S-48: una venta de contado sin detalle de pago y un abono viejo sin cuenta cuentan en efectivo', function () {
    app(CajaService::class)->fijarSaldoInicial('0', '2026-11-01');
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

it('RF-25 / S-49: una anulación de abono resta en el día en que se anula', function () {
    app(CajaService::class)->fijarSaldoInicial('0', '2026-11-01');
    $cliente = Cliente::factory()->create();
    actuarComo(Rol::Mostrador);
    $abono = abonar($cliente, 30000)->json('abono.id');

    Carbon::setTestNow('2026-11-21 15:00:00');
    actuarComo(Rol::Admin);
    $this->postJson("/api/abonos/{$abono}/anulaciones", ['motivo' => 'Cheque devuelto'])->assertCreated();

    $this->getJson('/api/caja/dias/2026-11-20')->assertJsonPath('resumen.recaudo_cartera', '30000.00');
    $this->getJson('/api/caja/dias/2026-11-21')->assertJsonPath('resumen.recaudo_cartera', '-30000.00')
        ->assertJsonPath('resumen.saldo_anterior', '30000.00'); // el 20 sin cerrar se encadena en vivo
});

it('RF-25: el cuadre mensual trae una fila por día, marca los días sin cerrar y suma los totales', function () {
    $mostrador = diaDeEjemplo();
    $this->postJson('/api/caja/cierres', ['fecha' => '2026-11-20', 'efectivo_contado' => 104000, 'observacion' => 'Faltaron mil'])->assertCreated();

    Carbon::setTestNow('2026-11-21 15:00:00');
    test()->actingAs($mostrador);
    movimientoCaja('Plan celulares', 20000)->assertCreated();

    actuarComo(Rol::Admin);
    $r = $this->getJson('/api/caja/meses/2026-11')->assertOk();
    $dias = collect($r->json('dias'))->keyBy('fecha');

    expect($dias)->toHaveCount(21)
        ->and($r->json('saldo_anterior'))->toBe('100000.00')
        ->and($dias['2026-11-20'])->toMatchArray([
            'cerrado' => true,
            'ventas_efectivo' => '130000.00',
            'ventas_billetera' => '20000.00',
            'ventas_credito' => '70000.00',
            'otros_ingresos' => '23000.00',
            'total_ingreso_dia' => '243000.00',
            'egresos' => '10000.00',
            'saldo_acumulado' => '205000.00',
            'faltante_meta' => '10657000.00',
            'arqueo' => '-1000.00',
            'observacion' => 'Faltaron mil',
        ])
        ->and($dias['2026-11-21'])->toMatchArray(['cerrado' => false, 'egresos' => '20000.00', 'saldo_acumulado' => '185000.00', 'arqueo' => null])
        ->and($r->json('totales.total_ingreso_dia'))->toBe('243000.00')
        ->and($r->json('totales.egresos'))->toBe('30000.00')
        ->and($r->json('totales.saldo_acumulado'))->toBe('185000.00')
        ->and($r->json('totales.dias_cerrados'))->toBe(1);

    actuarComo(Rol::Mostrador);
    $this->getJson('/api/caja/meses/2026-11')->assertForbidden();
});

it('RF-25 / S-49 / S-51: meta diaria y saldo inicial en Configuración, solo admin; el saldo inicial se fija una vez', function () {
    actuarComo(Rol::Admin);
    $this->getJson('/api/configuracion/meta-venta')->assertOk()->assertJsonPath('valor', '10900000.00');
    $this->putJson('/api/configuracion/meta-venta', ['valor' => 12000000])->assertOk()->assertJsonPath('valor', '12000000.00');
    $this->assertDatabaseHas('auditoria', ['tabla' => 'configuraciones', 'campo' => 'valor', 'valor_nuevo' => '12000000']);

    $this->getJson('/api/configuracion/saldo-caja-inicial')->assertOk()->assertJsonPath('saldo', null);
    $this->putJson('/api/configuracion/saldo-caja-inicial', ['valor' => 350000, 'fecha' => '2026-11-21'])->assertUnprocessable();
    $this->putJson('/api/configuracion/saldo-caja-inicial', ['valor' => 350000, 'fecha' => '2026-11-01'])->assertOk()
        ->assertJsonPath('saldo', ['valor' => '350000.00', 'fecha' => '2026-11-01']);
    $this->putJson('/api/configuracion/saldo-caja-inicial', ['valor' => 1, 'fecha' => '2026-11-01'])->assertUnprocessable()
        ->assertJsonPath('message', 'El saldo inicial de caja ya se fijó y no se puede cambiar.');

    actuarComo(Rol::Mostrador);
    $this->getJson('/api/caja/dias/2026-11-20')->assertJsonPath('resumen.meta_venta_diaria', '12000000.00')
        ->assertJsonPath('resumen.saldo_anterior', '350000.00');
    $this->putJson('/api/configuracion/meta-venta', ['valor' => 1])->assertForbidden();
    $this->putJson('/api/configuracion/saldo-caja-inicial', ['valor' => 1, 'fecha' => '2026-11-01'])->assertForbidden();
});
