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

/** RF-15: tres clientes con ventas el 5-oct (A), 10-nov (B) y 25-nov (C); hoy es 27-nov, así que ninguna es futura. */
function carteraTresPeriodos(): array
{
    $a = Cliente::factory()->create(['nombre' => 'Alfa Taller']);
    $b = Cliente::factory()->create(['nombre' => 'Beta Taller']);
    $c = Cliente::factory()->create(['nombre' => 'Gamma Motores']);
    fecharVenta(ventaCredito($a, [100])[0], '2026-10-05', '2026-11-04');
    fecharVenta(ventaCredito($b, [50])[0], '2026-11-10', '2026-12-10');
    fecharVenta(ventaCredito($c, [70])[0], '2026-11-25', '2026-12-25');

    return [$a, $b, $c];
}

function idsCartera(string $query): array
{
    return collect(test()->getJson('/api/cartera?'.$query)->assertOk()->json('data'))->pluck('cliente.id')->sort()->values()->all();
}

it('RF-15 (período): hasta solo trae lo cerrado hasta ese día, inclusive', function () {
    [$a, $b, $c] = carteraTresPeriodos();
    actuarComo(Rol::Admin);

    expect(idsCartera('corte=2026-11-30&hasta=2026-11-10'))->toBe([$a->id, $b->id])
        ->and(idsCartera('corte=2026-11-30&hasta=2026-11-09'))->toBe([$a->id])
        ->and(idsCartera('corte=2026-11-30&hasta=2026-11-25'))->toBe([$a->id, $b->id, $c->id]);
});

it('RF-15 (período): desde solo trae lo cerrado desde ese día, inclusive', function () {
    [$a, $b, $c] = carteraTresPeriodos();
    actuarComo(Rol::Admin);

    expect(idsCartera('corte=2026-11-30&desde=2026-11-10'))->toBe([$b->id, $c->id])
        ->and(idsCartera('corte=2026-11-30&desde=2026-11-11'))->toBe([$c->id])
        ->and(idsCartera('corte=2026-11-30&desde=2026-10-05'))->toBe([$a->id, $b->id, $c->id]);
});

it('RF-15 (período): desde y hasta se combinan con corte sin perder el saldo del corte', function () {
    [$a, $b, $c] = carteraTresPeriodos();
    actuarComo(Rol::Admin);

    // Corte al 15-nov: C (25-nov) no existe todavía; el período deja solo a B.
    $r = $this->getJson('/api/cartera?corte=2026-11-15&desde=2026-11-01&hasta=2026-12-31')->assertOk();
    expect($r->json('data'))->toHaveCount(1)
        ->and($r->json('data.0.cliente.id'))->toBe($b->id)
        ->and($r->json('data.0.ventas_periodo'))->toBe('50.00')
        ->and($r->json('data.0.saldo'))->toBe('50.00');

    // Corte anterior al período: nadie.
    $this->getJson('/api/cartera?corte=2026-10-31&desde=2026-11-01&hasta=2026-12-31')->assertOk()->assertJsonCount(0, 'data');
});

it('RF-15 (período): la búsqueda por nombre acota el período', function () {
    [$a, $b, $c] = carteraTresPeriodos();
    actuarComo(Rol::Admin);

    expect(idsCartera('desde=2026-10-01&hasta=2026-12-31&busqueda=Beta'))->toBe([$b->id])
        ->and(idsCartera('desde=2026-11-01&hasta=2026-11-30&busqueda=Alfa'))->toBe([]);
});

it('RF-15 (período): solo_con_saldo deja fuera al cliente ya saldado aunque vendió en el período', function () {
    [$a, $b, $c] = carteraTresPeriodos();
    actuarComo(Rol::Mostrador);
    abonar($b, '50')->assertCreated();

    expect(idsCartera('desde=2026-11-01&hasta=2026-11-20'))->toBe([$b->id])
        ->and(idsCartera('desde=2026-11-01&hasta=2026-11-20&solo_con_saldo=1'))->toBe([]);
});

it('RF-15 (período): una venta íntegramente reversada no cuenta en el período', function () {
    $cliente = Cliente::factory()->create();
    [$reversada, $items] = ventaCredito($cliente, [100, 40]);
    [$vigente] = ventaCredito($cliente, [30]);
    fecharVenta($reversada, '2026-11-05', '2026-12-05');
    fecharVenta($vigente, '2026-11-12', '2026-12-12');

    actuarComo(Rol::Mostrador);
    foreach ($items as $item) {
        $this->postJson("/api/ventas/{$reversada}/reversos", ['tipo' => 'devolucion', 'motivo' => 'Devolvió todo', 'venta_item_id' => $item])->assertOk();
    }

    $r = $this->getJson('/api/cartera?desde=2026-11-01&hasta=2026-11-30')->assertOk();
    expect($r->json('data.0.ventas_periodo'))->toBe('30.00')
        ->and($r->json('data.0.saldo'))->toBe('30.00')
        ->and($r->json('totales.ventas_periodo_total'))->toBe('30.00');

    // Si solo estuviera la reversada, el cliente no aparece en ese rango.
    expect(idsCartera('desde=2026-11-01&hasta=2026-11-10'))->toBe([]);
});
