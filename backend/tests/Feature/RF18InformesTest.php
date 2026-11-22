<?php

use App\Enums\Rol;
use App\Enums\TipoIngreso;
use App\Enums\TipoReverso;
use App\Models\Cliente;
use App\Models\User;
use App\Services\IngresoService;
use App\Services\ReversoService;

beforeEach(fn () => sembrarDemo());

/** RF-19: factura en borrador del mismo proveedor ligada a una remisión ya confirmada. */
function facturaSobreRemision(int $recibido, int $facturado, int $costo, array $encabezado = []): array
{
    $ref = referencia('RN-4410');
    $remision = compra($ref, $recibido, $costo, ['tipo' => TipoIngreso::Remision, ...$encabezado]);
    $factura = compra($ref, $facturado, $costo, ['tipo' => TipoIngreso::Factura, ...$encabezado], confirmar: false);
    app(IngresoService::class)->ligarRemisiones($factura, [$remision->id]);

    return [$remision, $factura];
}

const OCTUBRE = 'desde=2026-10-01&hasta=2026-10-31';

function octubre(int $dia): array
{
    return ['fecha' => sprintf('2026-10-%02d', $dia)];
}

function sumaDe(array $filas, string $campo): string
{
    return number_format(array_sum(array_column($filas, $campo)), 2, '.', '');
}

it('RF-18: compras del período con totales iguales a la suma de las filas', function () {
    compra(referencia('RN-4410'), 2, 1000, octubre(5));
    compra(referencia('DE-IM204-A'), 3, 500, [...octubre(20), ...aCredito()]);
    compra(referencia('RN-4410'), 1, 700, ['fecha' => '2026-09-30']);
    actuarComo(Rol::Admin);

    $r = $this->getJson('/api/reportes/compras?'.OCTUBRE)->assertOk();

    expect($r->json('total'))->toBe(2)
        ->and($r->json('totales.total'))->toBe('3500.00')
        ->and($r->json('totales.total'))->toBe(sumaDe($r->json('data'), 'total'))
        ->and($r->json('totales.base_sin_iva'))->toBe(sumaDe($r->json('data'), 'base_sin_iva'));
});

it('RF-18: el informe de compras filtra por proveedor', function () {
    compra(referencia('RN-4410'), 2, 1000, octubre(5));
    compra(referencia('DE-IM204-A'), 3, 500, octubre(5));
    actuarComo(Rol::Admin);

    $proveedor = referencia('DE-IM204-A')->proveedor_id;
    $this->getJson("/api/reportes/compras?proveedor_id={$proveedor}&".OCTUBRE)
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.proveedor.id', $proveedor)
        ->assertJsonPath('totales.total', '1500.00');
});

it('RF-18 / RF-19: una remisión cubierta por una factura confirmada no se cuenta dos veces en compras', function () {
    [, $factura] = facturaSobreRemision(2, 2, 1000, octubre(5));
    app(IngresoService::class)->confirmar($factura, User::factory()->rol(Rol::Logistica)->create());
    actuarComo(Rol::Admin);

    $this->getJson('/api/reportes/compras?'.OCTUBRE)
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.tipo', 'factura')
        ->assertJsonPath('totales.total', '2000.00');
});

it('RF-18: ventas a crédito netas de reversos, con base e IVA del precio (S-24)', function () {
    $cliente = Cliente::factory()->create();
    $parcial = ventaCreditoCerrada($cliente, 2, 119);
    $admin = actuarComo(Rol::Admin);
    app(ReversoService::class)->reversar($parcial, TipoReverso::Devolucion, 'Devolvió una', $parcial->items()->value('id'), $admin);

    $r = $this->getJson('/api/reportes/ventas-credito')->assertOk();

    expect($r->json('total'))->toBe(1)
        ->and($r->json('data.0.items'))->toBe(1)
        ->and($r->json('data.0.total'))->toBe('119.00')
        ->and($r->json('data.0.base'))->toBe('100.00')
        ->and($r->json('data.0.iva'))->toBe('19.00')
        ->and($r->json('totales.total'))->toBe(sumaDe($r->json('data'), 'total'));
});

it('RF-18: una venta a crédito reversada por completo no aparece en el informe', function () {
    $cliente = Cliente::factory()->create();
    $venta = ventaCreditoCerrada($cliente, 1, 100);
    $admin = actuarComo(Rol::Admin);
    app(ReversoService::class)->reversar($venta, TipoReverso::Anulacion, 'Error de cliente', null, $admin);

    $this->getJson('/api/reportes/ventas-credito')
        ->assertOk()
        ->assertJsonPath('total', 0)
        ->assertJsonPath('totales.total', '0.00');
});

it('RF-18: cartera de clientes con saldo y antigüedad; los totales suman las filas', function () {
    $ana = Cliente::factory()->create(['nombre' => 'Ana']);
    $beto = Cliente::factory()->create(['nombre' => 'Beto']);
    $saldado = Cliente::factory()->create(['nombre' => 'Carlos']);
    ingresar(referencia('RN-4410'), 1);
    ventaCreditoCerrada($ana, 1, 300);
    ventaCreditoCerrada($beto, 1, 200);
    ventaCreditoCerrada($saldado, 1, 100);
    actuarComo(Rol::Admin);
    $this->postJson("/api/clientes/{$saldado->id}/abonos", ['valor' => 100, 'medio' => 'efectivo'])->assertCreated();

    $r = $this->getJson('/api/reportes/cartera-clientes')->assertOk();

    expect(array_column(array_column($r->json('data'), 'cliente'), 'nombre'))->toBe(['Ana', 'Beto'])
        ->and($r->json('totales.saldo'))->toBe('500.00')
        ->and($r->json('totales.saldo'))->toBe(sumaDe($r->json('data'), 'saldo'))
        ->and($r->json('totales.rangos.al_dia'))->toBe('500.00');
});

it('RF-18: la cartera a una fecha de corte anterior no ve lo posterior', function () {
    $cliente = Cliente::factory()->create();
    ventaCreditoCerrada($cliente, 1, 300);
    actuarComo(Rol::Admin);

    $ayer = now()->subDay()->toDateString();
    $this->getJson("/api/reportes/cartera-clientes?corte={$ayer}")
        ->assertOk()
        ->assertJsonPath('corte', $ayer)
        ->assertJsonPath('total', 0);
});

it('RF-18: cuentas por pagar no duplican la remisión cubierta por la factura', function () {
    [, $factura] = facturaSobreRemision(2, 2, 1000, aCredito());
    app(IngresoService::class)->confirmar($factura, User::factory()->rol(Rol::Logistica)->create());
    compra(referencia('DE-IM204-A'), 1, 500, aCredito());
    actuarComo(Rol::Admin);

    $r = $this->getJson('/api/reportes/cuentas-por-pagar')->assertOk();

    expect($r->json('total'))->toBe(2)
        ->and($r->json('totales.saldo'))->toBe('2500.00')
        ->and($r->json('totales.saldo'))->toBe(sumaDe($r->json('data'), 'saldo'));
});

it('RF-18: estado de cuenta del cliente con saldo anterior, saldo corrido y saldo final igual al saldo', function () {
    $cliente = Cliente::factory()->create();
    ingresar(referencia('RN-4410'), 1);
    $this->travelTo(now()->subDays(20));
    ventaCreditoCerrada($cliente, 1, 400);
    $this->travelBack();

    $venta = ventaCreditoCerrada($cliente, 2, 50);
    $admin = actuarComo(Rol::Admin);
    $this->travel(1)->minutes();
    app(ReversoService::class)->reversar($venta, TipoReverso::Devolucion, 'Devolvió una', $venta->items()->value('id'), $admin);
    $this->postJson("/api/clientes/{$cliente->id}/abonos", ['valor' => 30, 'medio' => 'efectivo'])->assertCreated();

    $desde = now()->subDays(5)->toDateString();
    $r = $this->getJson("/api/clientes/{$cliente->id}/estado-cuenta?desde={$desde}")->assertOk();

    expect($r->json('saldo_anterior'))->toBe('400.00')
        ->and(array_column($r->json('data'), 'tipo'))->toBe(['venta', 'reverso', 'abono'])
        ->and(array_column($r->json('data'), 'saldo'))->toBe(['500.00', '450.00', '420.00'])
        ->and($r->json('totales.saldo_final'))->toBe('420.00');

    $this->getJson("/api/clientes/{$cliente->id}/saldo")->assertJsonPath('saldo', '420.00');
});

it('RF-18: el estado de cuenta muestra la anulación de un abono como cargo', function () {
    $cliente = Cliente::factory()->create();
    ventaCreditoCerrada($cliente, 1, 100);
    actuarComo(Rol::Admin);
    $abono = abonar($cliente, 60);
    $this->postJson("/api/abonos/{$abono}/anulaciones", ['motivo' => 'Cheque devuelto'])->assertCreated();

    $r = $this->getJson("/api/clientes/{$cliente->id}/estado-cuenta")->assertOk();

    expect(array_column($r->json('data'), 'tipo'))->toBe(['venta', 'abono', 'anulacion_abono'])
        ->and($r->json('totales.saldo_final'))->toBe('100.00');
});

it('RF-18 / RF-19: el estado de cuenta del proveedor descuenta la remisión cubierta y cuadra con el saldo', function () {
    [$remision, $factura] = facturaSobreRemision(2, 2, 1000, aCredito());
    app(IngresoService::class)->confirmar($factura, User::factory()->rol(Rol::Logistica)->create());
    actuarComo(Rol::Admin);
    $proveedor = $remision->proveedor_id;
    $this->postJson("/api/proveedores/{$proveedor}/pagos", ['valor' => 500, 'medio' => 'transferencia'])->assertCreated();

    $r = $this->getJson("/api/proveedores/{$proveedor}/estado-cuenta")->assertOk();

    expect(array_column($r->json('data'), 'tipo'))->toBe(['remision', 'factura', 'remision_cubierta', 'pago'])
        ->and($r->json('totales.saldo_final'))->toBe('1500.00');

    $this->getJson("/api/proveedores/{$proveedor}/saldo")->assertJsonPath('saldo', '1500.00');
});

it('RF-18: el estado de cuenta rechaza un período al revés', function () {
    $cliente = Cliente::factory()->create();
    actuarComo(Rol::Admin);

    $this->getJson("/api/clientes/{$cliente->id}/estado-cuenta?desde=2026-11-10&hasta=2026-11-01")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('hasta');
});

it('RF-18 / RF-19: remisiones pendientes incluye las ligadas a un borrador y excluye las ya facturadas', function () {
    $libre = compra(referencia('RN-4410'), 1, 100, ['tipo' => TipoIngreso::Remision]);
    [$enBorrador, $borrador] = facturaSobreRemision(1, 1, 100);
    [$cubierta, $confirmada] = facturaSobreRemision(1, 1, 100);
    app(IngresoService::class)->confirmar($confirmada, User::factory()->rol(Rol::Logistica)->create());
    actuarComo(Rol::Admin);

    $r = $this->getJson('/api/reportes/remisiones-pendientes?por_pagina=200')->assertOk();
    $filas = collect($r->json('data'))->keyBy('id');

    expect($filas->keys())->toContain($libre->id, $enBorrador->id)
        ->and($filas->keys())->not->toContain($cubierta->id)
        ->and($filas[$libre->id]['factura_borrador'])->toBeNull()
        ->and($filas[$enBorrador->id]['factura_borrador']['id'])->toBe($borrador->id)
        ->and($r->json('totales.total'))->toBe(sumaDe($r->json('data'), 'total'));
});

it('RF-18 / RF-19: diferencias de remisión con motivo y detalle por referencia', function () {
    [, $factura] = facturaSobreRemision(2, 1, 100);
    app(IngresoService::class)->confirmar($factura, User::factory()->rol(Rol::Logistica)->create(), true, 'El proveedor facturará el resto aparte');
    actuarComo(Rol::Admin);

    $this->getJson('/api/reportes/diferencias-remision')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.id', $factura->id)
        ->assertJsonPath('data.0.motivo', 'El proveedor facturará el resto aparte')
        ->assertJsonPath('data.0.detalle.0.codigo_referencia', 'RN-4410')
        ->assertJsonPath('data.0.detalle.0.recibido', 2)
        ->assertJsonPath('data.0.detalle.0.facturado', 1);
});

it('RF-18: la paginación es del servidor y los totales cubren todas las filas', function () {
    compra(referencia('RN-4410'), 1, 100, octubre(1));
    compra(referencia('RN-4410'), 1, 200, octubre(2));
    compra(referencia('RN-4410'), 1, 300, octubre(3));
    actuarComo(Rol::Admin);

    $this->getJson('/api/reportes/compras?por_pagina=2&page=2&'.OCTUBRE)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('total', 3)
        ->assertJsonPath('last_page', 2)
        ->assertJsonPath('totales.total', '600.00');
});

it('RF-18: los informes de cartera son solo del admin', function (Rol $rol) {
    $cliente = Cliente::factory()->create();
    actuarComo($rol);

    $this->getJson('/api/reportes/compras')->assertForbidden();
    $this->getJson('/api/reportes/cartera-clientes')->assertForbidden();
    $this->getJson("/api/clientes/{$cliente->id}/estado-cuenta")->assertForbidden();
})->with([Rol::Logistica, Rol::Mostrador]);
