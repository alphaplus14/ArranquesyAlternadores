<?php

use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\Reverso;
use App\Models\Unidad;
use App\Models\VentaItem;

beforeEach(fn () => sembrarDemo());

/**
 * Saldo del cliente según la adenda: ventas a crédito cerradas, netas de reversos (aún no hay
 * abonos). La cartera (RF-15) todavía no tiene endpoint; se calcula aquí con la misma regla.
 */
function saldoCliente(Cliente $cliente): float
{
    return (float) VentaItem::query()
        ->whereHas('venta', fn ($v) => $v->where('cliente_id', $cliente->id)->where('forma_pago', 'credito')->where('estado', 'cerrada'))
        ->whereDoesntHave('reversoItem')
        ->sum('precio_unitario');
}

function devolverPorApi(string $codigo, string $motivo = 'El cliente devolvió la pieza')
{
    return test()->postJson('/api/devoluciones', ['codigo' => $codigo, 'motivo' => $motivo, 'metodo_captura' => 'escaneado']);
}

it('RF-07 / S-27 (f): logística registra la devolución de una venta a crédito cerrada y el saldo del cliente baja', function () {
    $cliente = Cliente::factory()->create();
    actuarComo(Rol::Logistica);
    [$ventaId, $codigo] = venderComoMostrador('RN-4410', true, ['forma_pago' => 'credito', 'cliente_id' => $cliente->id]);
    expect(saldoCliente($cliente))->toBe(85000.0)
        ->and(referencia('RN-4410')->existencia)->toBe(1);

    $this->getJson("/api/devoluciones/consulta?codigo={$codigo}")->assertOk()
        ->assertJsonPath('venta.id', $ventaId)
        ->assertJsonPath('venta.forma_pago', 'credito')
        ->assertJsonPath('venta.cliente.nombre', $cliente->nombre)
        ->assertJsonPath('precio_unitario', '85000.00')
        ->assertJsonPath('reverso', null);

    $logistica = auth()->user();
    devolverPorApi($codigo)->assertCreated()
        ->assertJsonPath('venta.id', $ventaId)
        ->assertJsonPath('unidad.estado', 'disponible')
        ->assertJsonPath('reverso.tipo', 'devolucion')
        ->assertJsonPath('reverso.motivo', 'El cliente devolvió la pieza');

    expect(Unidad::where('codigo_barras', $codigo)->first()->estado->value)->toBe('disponible')
        ->and(referencia('RN-4410')->existencia)->toBe(2)
        ->and(saldoCliente($cliente))->toBe(0.0);

    $reverso = Reverso::firstOrFail();
    expect($reverso->venta_id)->toBe($ventaId)->and($reverso->usuario_id)->toBe($logistica->id);
    $this->assertDatabaseHas('movimientos_inventario', [
        'tipo' => 'reverso', 'origen_type' => 'reverso', 'origen_id' => $reverso->id, 'metodo_captura' => 'escaneado',
    ]);
});

it('RF-07 / S-27 (f): una venta de contado cerrada también se devuelve; el cliente es Mostrador (null)', function () {
    actuarComo(Rol::Admin);
    [$ventaId, $codigo] = venderComoMostrador('AC-77812');

    devolverPorApi($codigo)->assertCreated()
        ->assertJsonPath('venta.id', $ventaId)
        ->assertJsonPath('venta.forma_pago', 'contado')
        ->assertJsonPath('venta.cliente', null);
    expect(referencia('AC-77812')->existencia)->toBe(2);
});

it('RF-07 / S-27 (f) / criterio 6: en una venta abierta se rechaza con el mensaje de anular el ítem', function () {
    actuarComo(Rol::Logistica);
    [$ventaId, $codigo] = venderComoMostrador('RN-4410', false);

    devolverPorApi($codigo)->assertUnprocessable()
        ->assertJsonPath('message', "La unidad {$codigo} está en la venta abierta #{$ventaId}: anule el ítem en la venta.");
    $this->getJson("/api/devoluciones/consulta?codigo={$codigo}")->assertUnprocessable();

    expect(Reverso::count())->toBe(0)->and(referencia('RN-4410')->existencia)->toBe(1);
});

it('RF-07 / S-27 (f): una unidad ya devuelta, no vendida, retirada o inexistente se rechaza', function () {
    actuarComo(Rol::Logistica);
    [, $codigo] = venderComoMostrador('RN-4410');
    devolverPorApi($codigo)->assertCreated();

    devolverPorApi($codigo)->assertUnprocessable()
        ->assertJsonPath('message', "La unidad {$codigo} ya fue devuelta o anulada: está en inventario.");
    expect(Reverso::count())->toBe(1)->and(referencia('RN-4410')->existencia)->toBe(2);

    $nueva = unidadDe('AC-77812')->codigo_barras;
    devolverPorApi($nueva)->assertUnprocessable()->assertJsonPath('message', "La unidad {$nueva} no se ha vendido: está en inventario.");

    $this->postJson('/api/ajustes/salidas', ['motivo' => 'dano', 'codigos' => [['codigo' => $nueva, 'metodo_captura' => 'escaneado']]])->assertCreated();
    devolverPorApi($nueva)->assertUnprocessable()->assertJsonPath('message', "La unidad {$nueva} está retirada del inventario; no está vendida.");

    devolverPorApi('ZZ9999999')->assertNotFound();
    // S-38: el motivo ya no es obligatorio en este flujo (se guarda "Devolución del cliente"); ver S38DevolucionesCantidadTest.
});

it('RF-07 / S-27: una unidad devuelta y vendida otra vez se devuelve con su venta más reciente', function () {
    actuarComo(Rol::Logistica);
    [, $codigo] = venderComoMostrador('RN-4410');
    devolverPorApi($codigo)->assertCreated();

    // Mostrador la vende de nuevo (unidadDe toma la primera disponible: la misma).
    [$segundaVenta, $mismoCodigo] = venderComoMostrador('RN-4410');
    expect($mismoCodigo)->toBe($codigo);

    devolverPorApi($codigo)->assertCreated()->assertJsonPath('venta.id', $segundaVenta);
    expect(Reverso::count())->toBe(2);
});

it('RF-07: la devolución de logística no cambia la ruta de reversos de mostrador', function () {
    actuarComo(Rol::Logistica);
    [$ventaId] = venderComoMostrador('RN-4410');

    $this->postJson("/api/ventas/{$ventaId}/reversos", ['tipo' => 'devolucion', 'motivo' => 'Prueba'])->assertForbidden();
});

it('RF-07 / S-27 (g) / criterio 8: mostrador recibe 403 en devoluciones; logística y admin pasan', function () {
    actuarComo(Rol::Logistica);
    [, $codigo] = venderComoMostrador('RN-4410');

    actuarComo(Rol::Mostrador);
    $this->getJson("/api/devoluciones/consulta?codigo={$codigo}")->assertForbidden();
    devolverPorApi($codigo)->assertForbidden();
    expect(Reverso::count())->toBe(0);

    actuarComo(Rol::Logistica);
    $this->getJson("/api/devoluciones/consulta?codigo={$codigo}")->assertOk();

    actuarComo(Rol::Admin);
    devolverPorApi($codigo)->assertCreated();
});
