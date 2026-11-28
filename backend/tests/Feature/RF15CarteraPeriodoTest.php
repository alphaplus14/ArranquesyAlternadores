<?php

use App\Enums\Rol;
use App\Models\Cliente;

/*
 * RF-15 / RF-18: GET /api/cartera con desde/hasta = ventas a crédito del período. El saldo sigue
 * siendo el neto vigente; `corte` sigue funcionando solo.
 */

beforeEach(fn () => sembrarDemo());

it('RF-15 (período): solo salen los clientes con ventas cerradas en el rango y el saldo no cambia', function () {
    $a = Cliente::factory()->create();
    $b = Cliente::factory()->create();
    [$va1] = ventaCredito($a, [100]);
    [$va2] = ventaCredito($a, [50]);
    [$vb] = ventaCredito($b, [70]);
    fecharVenta($va1, '2026-10-05', '2026-11-04');
    fecharVenta($va2, '2026-11-10', '2026-12-10');
    fecharVenta($vb, '2026-09-01', '2026-10-01');

    actuarComo(Rol::Mostrador);
    $r = $this->getJson('/api/cartera?desde=2026-11-01&hasta=2026-11-30')->assertOk();
    expect($r->json('data'))->toHaveCount(1)
        ->and($r->json('data.0.cliente.id'))->toBe($a->id)
        ->and($r->json('data.0.ventas_periodo'))->toBe('50.00')
        ->and($r->json('data.0.saldo'))->toBe('150.00')
        ->and($r->json('totales.ventas_periodo_total'))->toBe('50.00')
        ->and($r->json('desde'))->toBe('2026-11-01');

    // Un solo extremo: desde abierto hacia adelante.
    $r = $this->getJson('/api/cartera?desde=2026-10-01')->assertOk();
    expect(collect($r->json('data'))->pluck('cliente.id')->all())->toBe([$a->id]);

    // Sin rango el listado es el de siempre (sin campos de período en los totales).
    $r = $this->getJson('/api/cartera')->assertOk();
    expect($r->json('data'))->toHaveCount(2)
        ->and($r->json('totales'))->not->toHaveKey('ventas_periodo_total');
});

it('RF-15 (período): corte se mantiene compatible', function () {
    $a = Cliente::factory()->create();
    [$v] = ventaCredito($a, [100]);
    fecharVenta($v, '2026-10-05', '2026-11-04');

    actuarComo(Rol::Admin);
    $this->getJson('/api/cartera?corte=2026-10-01')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/cartera?corte=2026-10-06')->assertOk()->assertJsonCount(1, 'data');
});

it('RF-15 (período): desde mayor que hasta es 422', function () {
    actuarComo(Rol::Mostrador);
    $this->getJson('/api/cartera?desde=2026-11-30&hasta=2026-11-01')->assertUnprocessable()->assertJsonValidationErrors('hasta');
});

it('RF-15 (período): logística recibe 403', function () {
    actuarComo(Rol::Logistica);
    $this->getJson('/api/cartera?desde=2026-11-01&hasta=2026-11-30')->assertForbidden();
});
