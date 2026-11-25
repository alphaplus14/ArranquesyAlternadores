<?php

use App\Enums\Rol;
use App\Models\Cliente;
use Illuminate\Support\Carbon;

/*
 * RF-18 (S-12, S-13, S-30): ventas a crédito por cliente con FIFO por venta y estado de cuenta.
 */

beforeEach(fn () => sembrarDemo());

it('RF-18 / S-12 (g) / criterio 4: el detalle aplica el abono FIFO por venta: la vieja saldada y la nueva con pendiente 40', function () {
    $cliente = Cliente::factory()->create(['nombre' => 'Taller FIFO']);
    $hoy = Carbon::now('America/Bogota')->startOfDay();
    [$vieja] = ventaCredito($cliente, [60]);
    [$nueva] = ventaCredito($cliente, [50]);
    fecharVenta($vieja, $hoy->copy()->subDays(70)->toDateString(), $hoy->copy()->subDays(40)->toDateString());
    fecharVenta($nueva, $hoy->copy()->subDays(5)->toDateString(), $hoy->copy()->addDays(25)->toDateString());

    actuarComo(Rol::Mostrador);
    // Antes del abono la vieja está vencida hace 40 días (31-60).
    $this->getJson("/api/clientes/{$cliente->id}/cartera")->assertOk()
        ->assertJsonPath('ventas.0.id', $vieja)
        ->assertJsonPath('ventas.0.estado', 'vencida')
        ->assertJsonPath('ventas.0.estado_texto', 'vencida hace 40 días')
        ->assertJsonPath('ventas.0.rango', 'd31_60');

    abonar($cliente, 70)->assertCreated();

    $this->getJson("/api/clientes/{$cliente->id}/cartera")->assertOk()
        ->assertJsonPath('ventas.0.total', '60.00')
        ->assertJsonPath('ventas.0.aplicado', '60.00')
        ->assertJsonPath('ventas.0.pendiente', '0.00')
        ->assertJsonPath('ventas.0.estado', 'al_dia')
        ->assertJsonPath('ventas.0.estado_texto', 'al día')
        ->assertJsonPath('ventas.1.id', $nueva)
        ->assertJsonPath('ventas.1.aplicado', '10.00')
        ->assertJsonPath('ventas.1.pendiente', '40.00')
        ->assertJsonPath('ventas.1.estado', 'por_vencer')
        ->assertJsonPath('ventas.1.estado_texto', 'vence en 25 días')
        ->assertJsonPath('ventas.1.rango', 'al_dia')
        ->assertJsonPath('resumen.saldo', '40.00')
        ->assertJsonPath('resumen.vencido', '0.00')
        ->assertJsonPath('resumen.antiguedad.al_dia', '40.00')
        ->assertJsonPath('resumen.cliente.nombre', 'Taller FIFO')
        ->assertJsonPath('abonos.0.valor', '70.00')
        ->assertJsonPath('abonos.0.anulado', false);
});

it('RF-18 / criterio 6: el estado de cuenta lista venta, reverso, abono y anulación en orden, con saldo acumulado', function () {
    $cliente = Cliente::factory()->create();
    [$ventaId, $items] = ventaCredito($cliente, [100, 50]);

    actuarComo(Rol::Mostrador);
    $this->postJson("/api/ventas/{$ventaId}/reversos", ['tipo' => 'devolucion', 'motivo' => 'Pieza equivocada', 'venta_item_id' => $items[1]])->assertOk();
    $abonoId = abonar($cliente, 40, ['referencia' => 'EF-1'])->json('abono.id');

    $admin = actuarComo(Rol::Admin);
    $this->postJson("/api/abonos/{$abonoId}/anulaciones", ['motivo' => 'Duplicado'])->assertCreated();

    $estado = $this->getJson("/api/clientes/{$cliente->id}/estado-cuenta")->assertOk();
    expect(collect($estado->json('movimientos'))->pluck('tipo')->all())->toBe(['venta', 'reverso', 'abono', 'anulacion_abono'])
        ->and(collect($estado->json('movimientos'))->pluck('saldo')->all())->toBe(['150.00', '100.00', '60.00', '100.00']);

    $estado->assertJsonPath('movimientos.0.cargo', '150.00')
        ->assertJsonPath('movimientos.1.abono', '50.00')
        ->assertJsonPath('movimientos.1.motivo', 'Pieza equivocada')
        ->assertJsonPath('movimientos.2.anulado', true)
        ->assertJsonPath('movimientos.2.anulacion.motivo', 'Duplicado')
        ->assertJsonPath('movimientos.2.anulacion.usuario', $admin->name)
        ->assertJsonPath('movimientos.3.cargo', '40.00')
        ->assertJsonPath('saldo_final', '100.00');

    // El saldo final coincide con el de la cartera (una sola fuente).
    $this->getJson("/api/clientes/{$cliente->id}/cartera")->assertJsonPath('resumen.saldo', '100.00');
});

it('RF-18: un ítem anulado con la venta abierta aparece como reverso justo después de la venta, no antes', function () {
    $cliente = Cliente::factory()->create();
    [$ventaId, $items] = ventaCredito($cliente, [100, 30], cerrar: false);

    actuarComo(Rol::Mostrador);
    $this->postJson("/api/ventas/{$ventaId}/reversos", ['tipo' => 'anulacion', 'motivo' => 'Se escaneó de más', 'venta_item_id' => $items[1]])->assertOk();
    cerrarVenta($ventaId)->assertOk();

    $estado = $this->getJson("/api/clientes/{$cliente->id}/estado-cuenta")->assertOk();
    expect(collect($estado->json('movimientos'))->pluck('tipo')->all())->toBe(['venta', 'reverso'])
        ->and($estado->json('movimientos.0.cargo'))->toBe('130.00')
        ->and($estado->json('movimientos.1.abono'))->toBe('30.00')
        ->and($estado->json('movimientos.1.dia'))->toBe($estado->json('movimientos.0.dia'))
        ->and($estado->json('saldo_final'))->toBe('100.00');
    $this->getJson("/api/clientes/{$cliente->id}/cartera")->assertJsonPath('resumen.saldo', '100.00');
});

it('RF-18: con desde/hasta el estado de cuenta arranca con el saldo anterior', function () {
    $cliente = Cliente::factory()->create();
    $hoy = Carbon::now('America/Bogota')->startOfDay();
    [$vieja] = ventaCredito($cliente, [100]);
    fecharVenta($vieja, $hoy->copy()->subDays(30)->toDateString(), $hoy->copy()->toDateString());
    ventaCredito($cliente, [70]);

    actuarComo(Rol::Mostrador);
    abonar($cliente, 20, ['fecha' => $hoy->copy()->subDays(10)->toDateString()])->assertCreated();

    $desde = $hoy->copy()->subDays(5)->toDateString();
    $this->getJson("/api/clientes/{$cliente->id}/estado-cuenta?desde={$desde}")->assertOk()
        ->assertJsonPath('saldo_inicial', '80.00')
        ->assertJsonCount(1, 'movimientos')
        ->assertJsonPath('movimientos.0.tipo', 'venta')
        ->assertJsonPath('movimientos.0.saldo', '150.00')
        ->assertJsonPath('saldo_final', '150.00');

    $hasta = $hoy->copy()->subDays(20)->toDateString();
    $this->getJson("/api/clientes/{$cliente->id}/estado-cuenta?hasta={$hasta}")->assertOk()
        ->assertJsonCount(1, 'movimientos')
        ->assertJsonPath('saldo_final', '100.00');

    $this->getJson("/api/clientes/{$cliente->id}/estado-cuenta?desde={$desde}&hasta={$hasta}")->assertUnprocessable();
});

it('RF-18 / S-30: detalle y estado de cuenta son de admin y mostrador; logística 403; un cliente sin movimientos responde vacío', function () {
    $cliente = Cliente::factory()->create();

    actuarComo(Rol::Logistica);
    $this->getJson("/api/clientes/{$cliente->id}/cartera")->assertForbidden();
    $this->getJson("/api/clientes/{$cliente->id}/estado-cuenta")->assertForbidden();

    foreach ([Rol::Admin, Rol::Mostrador] as $rol) {
        actuarComo($rol);
        $this->getJson("/api/clientes/{$cliente->id}/cartera")->assertOk()
            ->assertJsonPath('resumen.saldo', '0.00')
            ->assertJsonPath('resumen.cliente.id', $cliente->id)
            ->assertJsonCount(0, 'ventas')
            ->assertJsonCount(0, 'abonos');
        $this->getJson("/api/clientes/{$cliente->id}/estado-cuenta")->assertOk()
            ->assertJsonCount(0, 'movimientos')
            ->assertJsonPath('saldo_final', '0.00');
    }
});
