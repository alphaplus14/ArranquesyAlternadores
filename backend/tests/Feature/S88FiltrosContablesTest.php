<?php

use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\Proveedor;
use App\Models\Referencia;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
 * S-88 (RF-17, RF-18, RF-29, RF-30): filtros e historial del módulo contable. Solo consulta: los
 * filtros se resuelven en la consulta o sobre la colección ya calculada; los totales son los de TODO
 * lo filtrado (no la página). Sin cambios de cálculo ni de permisos de escritura.
 */

beforeEach(fn () => sembrarDemo());

/** Proveedor nuevo con NIT y una referencia suya. */
function S88Proveedor(string $nombre, ?string $nit = null): Referencia
{
    $p = Proveedor::factory()->create(['nombre' => $nombre, 'nit' => $nit]);

    return Referencia::factory()->create(['proveedor_id' => $p->id, 'precio_venta' => 50000]);
}

/** Venta a crédito de solo valor (S-73) registrada por el mostrador; devuelve el id. */
function S88SoloValor(Cliente $cliente, int $valor, string $tipo = 'factura', ?string $numero = null): int
{
    $anterior = auth()->user();
    actuarComo(Rol::Mostrador);
    $id = test()->postJson('/api/ventas/credito', ['cliente_id' => $cliente->id, 'tipo_documento' => $tipo, 'valor_total' => $valor, 'numero_documento' => $numero])
        ->assertCreated()->json('id');
    $anterior ? test()->actingAs($anterior) : null;

    return $id;
}

/* ───────────── Cuentas por pagar y cartera ───────────── */

it('S-88: cuentas por pagar filtra por nombre sin tildes ni mayúsculas y por NIT normalizado; los totales son de lo filtrado', function () {
    Carbon::setTestNow('2026-09-01 10:00:00');
    $ajover = S88Proveedor('Ajover Repuestos Ñandú', '900.123.456-7');
    compra($ajover, 1, 1000, aCredito('2026-09-20')); // vencerá
    Carbon::setTestNow('2026-11-20 10:00:00');
    $otro = S88Proveedor('Baterías del Sur', '800111222');
    compra($otro, 1, 3000, aCredito('2026-12-31'));
    actuarComo(Rol::Contable);

    $nombres = fn (array $p) => collect($this->getJson('/api/reportes/cuentas-por-pagar?'.http_build_query($p))->assertOk()->json('data'))->pluck('proveedor.nombre')->all();

    expect($nombres(['q' => 'AJOVER nandu']))->toBe(['Ajover Repuestos Ñandú'])
        ->and($nombres(['q' => '900123456']))->toBe(['Ajover Repuestos Ñandú'])
        ->and($nombres(['q' => '900.123.456-7']))->toBe(['Ajover Repuestos Ñandú'])
        ->and($nombres(['estado' => 'con_vencido']))->toBe(['Ajover Repuestos Ñandú'])
        ->and($nombres(['estado' => 'al_dia', 'q' => 'baterias']))->toBe(['Baterías del Sur'])
        ->and($nombres(['antiguedad' => 'mas_de_60']))->toBe(['Ajover Repuestos Ñandú'])
        ->and($nombres(['antiguedad' => 'mas_de_90']))->toBe([]);

    // Totales de TODO lo filtrado aunque la página tenga una sola fila.
    $r = $this->getJson('/api/reportes/cuentas-por-pagar?'.http_build_query(['q' => 'r', 'por_pagina' => 1, 'orden' => 'saldo_desc']))->assertOk();
    $todas = collect($this->getJson('/api/reportes/cuentas-por-pagar?'.http_build_query(['q' => 'r', 'por_pagina' => 200]))->json('data'));
    expect($r->json('data'))->toHaveCount(1)
        ->and($r->json('total'))->toBe($todas->count())
        ->and($r->json('totales.saldo'))->toBe(number_format($todas->sum(fn ($f) => (float) $f['saldo']), 2, '.', ''))
        ->and($r->json('totales.titulares'))->toBe($todas->count())
        ->and((float) $r->json('data.0.saldo'))->toBe($todas->max(fn ($f) => (float) $f['saldo']));
});

it('S-88: cartera de clientes filtra por documento y por saldo a favor', function () {
    $ana = Cliente::factory()->create(['nombre' => 'Ana Taller', 'documento' => '1.020.304']);
    $beto = Cliente::factory()->create(['nombre' => 'Beto Motos']);
    ventaCredito($ana, [100]);
    ventaCredito($beto, [100]);
    abonoHistorico($beto, 150); // anterior a S-79: queda a favor
    actuarComo(Rol::Contable);

    $ids = fn (array $p) => collect($this->getJson('/api/reportes/cartera-clientes?'.http_build_query($p))->assertOk()->json('data'))->pluck('cliente.id')->all();
    expect($ids(['q' => '1020304']))->toBe([$ana->id])
        ->and($ids(['a_favor' => 1]))->toBe([$beto->id]);
    $this->getJson('/api/reportes/cartera-clientes?estado=otro')->assertUnprocessable();
});

/* ───────────── Historial de pagos y abonos ───────────── */

it('S-88: historial de pagos: vigentes por defecto, anulados aparte, búsqueda por factura, fechas y totales', function () {
    $ref = referencia('RN-4410');
    $a = compra($ref, 1, 500, ['numero_documento' => 'FA-777', ...aCredito()]);
    $b = compra($ref, 1, 500, ['numero_documento' => 'FB-888', ...aCredito()]);
    actuarComo(Rol::Admin);
    pagar($ref->proveedor_id, 100, ['ingreso_id' => $a->id, 'referencia' => 'TR-1'])->assertCreated();
    pagar($ref->proveedor_id, 200, ['ingreso_id' => $b->id])->assertCreated();
    $anulado = pagar($ref->proveedor_id, 50, ['ingreso_id' => $b->id])->assertCreated()->json('id');
    $this->postJson("/api/pagos/{$anulado}/anulaciones", ['motivo' => 'Duplicado'])->assertCreated();
    pagoHistorico($ref->proveedor_id, 30); // sin factura

    actuarComo(Rol::Contable);
    $url = "/api/proveedores/{$ref->proveedor_id}/pagos";
    $r = $this->getJson($url)->assertOk();
    expect(collect($r->json('data'))->pluck('valor')->map(fn ($v) => (int) $v)->sort()->values()->all())->toBe([30, 100, 200])
        ->and($r->json('totales'))->toMatchArray(['vigente' => '330.00', 'cantidad' => 3, 'anulado' => '50.00', 'cantidad_anulados' => 1]);

    $anulados = $this->getJson("{$url}?estado=anulados")->assertOk();
    expect($anulados->json('data'))->toHaveCount(1)
        ->and($anulados->json('data.0.anulacion.motivo'))->toBe('Duplicado');
    expect($this->getJson("{$url}?estado=todos")->json('total'))->toBe(4)
        ->and(collect($this->getJson("{$url}?q=FB-888&estado=todos")->json('data'))->pluck('ingreso.numero_documento')->unique()->values()->all())->toBe(['FB-888'])
        ->and($this->getJson("{$url}?q=TR-1")->json('total'))->toBe(1)
        ->and($this->getJson("{$url}?desde=2099-01-01")->json('total'))->toBe(0);
    $this->getJson("{$url}?desde=2026-11-20&hasta=2026-11-01")->assertUnprocessable()->assertJsonValidationErrors('hasta');
});

it('S-88: historial de abonos del cliente con venta aplicada o "General" y anulados aparte', function () {
    $cliente = Cliente::factory()->create();
    [$venta] = ventaCredito($cliente, [100]);
    actuarComo(Rol::Mostrador);
    abonar($cliente, 40, ['venta_id' => $venta, 'referencia' => 'RC-9'])->assertCreated();
    $abonoAnulado = abonar($cliente, 10, ['venta_id' => $venta])->assertCreated()->json('abono.id');
    actuarComo(Rol::Admin);
    $this->postJson("/api/abonos/{$abonoAnulado}/anulaciones", ['motivo' => 'Error'])->assertCreated();
    abonoHistorico($cliente, 5);

    actuarComo(Rol::Contable);
    $r = $this->getJson("/api/clientes/{$cliente->id}/abonos")->assertOk();
    expect($r->json('totales'))->toMatchArray(['vigente' => '45.00', 'cantidad' => 2, 'anulado' => '10.00', 'cantidad_anulados' => 1])
        ->and(collect($r->json('data'))->pluck('aplicado_a')->sort()->values()->all())->toBe(['General', "Venta #{$venta}"])
        ->and($this->getJson("/api/clientes/{$cliente->id}/abonos?q=RC-9")->json('total'))->toBe(1)
        ->and($this->getJson("/api/clientes/{$cliente->id}/abonos?q=%23{$venta}&estado=todos")->json('total'))->toBe(2);
});

/* ───────────── Saldo del cliente para el contable ───────────── */

it('S-88: el saldo del cliente coincide con la cartera del mostrador, incluidas las ventas de solo valor', function () {
    $cliente = Cliente::factory()->create(['documento' => '777']);
    [$conItems] = ventaCredito($cliente, [100]);
    $soloValor = S88SoloValor($cliente, 300000, 'remision');
    actuarComo(Rol::Mostrador);
    abonar($cliente, 40, ['venta_id' => $conItems])->assertCreated();
    abonar($cliente, 100000, ['venta_id' => $soloValor])->assertCreated();

    $cartera = $this->getJson("/api/clientes/{$cliente->id}/cartera")->assertOk();
    actuarComo(Rol::Contable);
    $saldo = $this->getJson("/api/clientes/{$cliente->id}/saldo")->assertOk();

    expect($saldo->json('saldo'))->toBe($cartera->json('resumen.saldo'))
        ->and($saldo->json('cliente.documento'))->toBe('777');
    $pendientes = collect($saldo->json('documentos'))->keyBy('id');
    foreach ($cartera->json('ventas') as $v) {
        expect($pendientes[$v['id']]['pendiente'])->toBe($v['pendiente']);
    }
    expect($pendientes[$soloValor])->toMatchArray(['tipo' => 'remision', 'valor' => '300000.00', 'abonado' => '100000.00', 'pendiente' => '200000.00']);
});

/* ───────────── Estado de cuenta ───────────── */

it('S-88: el estado de cuenta con tipo y búsqueda oculta filas sin cambiar el saldo corrido ni los totales del período', function () {
    $ref = referencia('RN-4410');
    $f = compra($ref, 1, 500, ['numero_documento' => 'EC-1', ...aCredito()]);
    actuarComo(Rol::Admin);
    pagar($ref->proveedor_id, 200, ['ingreso_id' => $f->id])->assertCreated();

    actuarComo(Rol::Contable);
    $url = "/api/proveedores/{$ref->proveedor_id}/estado-cuenta";
    $todo = $this->getJson($url)->assertOk();
    $pagos = $this->getJson("{$url}?tipo=abonos")->assertOk();
    $cargos = $this->getJson("{$url}?tipo=cargos&q=ec-1")->assertOk();

    $fila = collect($todo->json('data'))->firstWhere('tipo', 'pago');
    expect($pagos->json('data'))->toHaveCount(1)
        ->and($pagos->json('data.0.saldo'))->toBe($fila['saldo'])
        ->and($pagos->json('totales'))->toBe($todo->json('totales'))
        ->and($pagos->json('movimientos_periodo'))->toBe(count($todo->json('data')))
        ->and($pagos->json('filtrado'))->toBeTrue()
        ->and(collect($cargos->json('data'))->pluck('tipo')->unique()->all())->toBe(['remision']);
});

it('S-88: el contable ve el estado de cuenta de un cliente (antes 403) con el formato del de proveedores', function () {
    $cliente = Cliente::factory()->create();
    [$venta] = ventaCredito($cliente, [100]);
    actuarComo(Rol::Mostrador);
    abonar($cliente, 30, ['venta_id' => $venta])->assertCreated();

    actuarComo(Rol::Contable);
    $r = $this->getJson("/api/reportes/clientes/{$cliente->id}/estado-cuenta")->assertOk();
    expect(collect($r->json('data'))->pluck('tipo')->all())->toBe(['venta', 'abono'])
        ->and($r->json('data.1.saldo'))->toBe('70.00')
        ->and($r->json('totales.saldo_final'))->toBe('70.00')
        ->and($r->json('data.1.detalle'))->toContain("Venta #{$venta}");
    expect(collect($this->getJson("/api/reportes/clientes/{$cliente->id}/estado-cuenta?tipo=abonos")->json('data'))->pluck('tipo')->all())->toBe(['abono']);
});

/* ───────────── Vencimientos ───────────── */

it('S-88: vencimientos filtra por estado, texto y rango; los totales son de lo filtrado', function () {
    config(['cartera.aviso_dias' => 30]);
    Carbon::setTestNow('2026-10-01 10:00:00');
    $ref = S88Proveedor('Vencidos SAS');
    compra($ref, 1, 300, ['numero_documento' => 'V-1', ...aCredito('2026-11-01')]);
    compra($ref, 1, 200, ['numero_documento' => 'V-2', ...aCredito('2026-11-30')]);
    Carbon::setTestNow('2026-11-20 10:00:00');
    actuarComo(Rol::Contable);

    $docs = fn (array $p) => collect($this->getJson('/api/vencimientos?'.http_build_query(['tipo' => 'por_pagar', ...$p]))->assertOk()->json('data'))->pluck('documento')->all();
    // compra() crea remisiones salvo que se pida factura.
    expect($docs(['estado' => 'vencidas', 'q' => 'vencidos']))->toBe(['Remisión V-1'])
        ->and($docs(['estado' => 'por_vencer', 'q' => 'vencidos']))->toBe(['Remisión V-2'])
        ->and($docs(['q' => 'v-2']))->toBe(['Remisión V-2'])
        ->and($docs(['q' => 'vencidos', 'vence_desde' => '2026-11-15', 'vence_hasta' => '2026-12-31']))->toBe(['Remisión V-2'])
        ->and($docs(['q' => 'vencidos', 'orden' => 'pendiente_desc']))->toBe(['Remisión V-1', 'Remisión V-2']);
    $this->getJson('/api/vencimientos?q=vencidos&estado=vencidas')->assertJsonPath('totales.todas.pendiente', '300.00');
});

/* ───────────── Informes ───────────── */

it('S-88: compras por forma de pago, tipo y número; ventas a crédito por tipo, estado y número; remisiones por antigüedad', function () {
    Carbon::setTestNow('2026-11-20 10:00:00');
    $ref = referencia('RN-4410');
    compra($ref, 1, 100, ['numero_documento' => 'CR-1', ...aCredito()]);
    compra($ref, 1, 100, ['numero_documento' => 'CO-1']);
    $cliente = Cliente::factory()->create();
    $factura = S88SoloValor($cliente, 1000, 'factura', 'FE-55');
    $remision = S88SoloValor($cliente, 2000, 'remision');
    actuarComo(Rol::Admin);
    $this->postJson("/api/ventas/{$remision}/reversos", ['tipo' => 'anulacion', 'motivo' => 'Se registró por error'])->assertOk();

    actuarComo(Rol::Contable);
    $compras = fn (array $p) => collect($this->getJson('/api/reportes/compras?'.http_build_query($p))->assertOk()->json('data'))->pluck('numero_documento')->sort()->values()->all();
    expect($compras(['forma_pago' => 'credito', 'q' => 'cr-']))->toBe(['CR-1'])
        ->and($compras(['forma_pago' => 'contado', 'q' => 'CO']))->toBe(['CO-1'])
        ->and($compras(['tipo' => 'factura', 'q' => 'CR-1']))->toBe([])
        ->and($compras(['tipo' => 'remision', 'q' => 'CR-1']))->toBe(['CR-1']);

    $ventas = fn (array $p) => collect($this->getJson('/api/reportes/ventas-credito?'.http_build_query(['cliente_id' => $cliente->id, ...$p]))->assertOk()->json('data'))->pluck('id')->all();
    expect($ventas(['q' => 'fe-55']))->toBe([$factura])
        ->and($ventas(['tipo' => 'factura']))->toBe([$factura]);
    expect($ventas(['estado' => 'anuladas']))->toBe([$remision])
        ->and($ventas([]))->toBe([$factura])
        ->and($ventas(['estado' => 'todas']))->toBe([$factura, $remision]);

    $this->getJson('/api/reportes/remisiones-pendientes?antiguedad=mas_de_90')->assertOk()->assertJsonPath('totales.total', '0.00');
    $this->getJson('/api/reportes/compras?forma_pago=otra')->assertUnprocessable();
});

/* ───────────── Permisos ───────────── */

it('S-88: contable y admin consultan; mostrador y logística reciben 403', function (Rol $rol, int $codigo) {
    $cliente = Cliente::factory()->create();
    $proveedor = referencia('RN-4410')->proveedor_id;
    actuarComo($rol);

    foreach ([
        "/api/proveedores/{$proveedor}/pagos",
        "/api/clientes/{$cliente->id}/abonos",
        "/api/clientes/{$cliente->id}/saldo",
        "/api/reportes/clientes/{$cliente->id}/estado-cuenta",
        '/api/reportes/cuentas-por-pagar?q=x',
        '/api/vencimientos?estado=vencidas',
    ] as $url) {
        expect($this->getJson($url)->status())->toBe($codigo, $url);
    }
})->with([
    'contable' => [Rol::Contable, 200],
    'admin' => [Rol::Admin, 200],
    'mostrador' => [Rol::Mostrador, 403],
    'logística' => [Rol::Logistica, 403],
]);
