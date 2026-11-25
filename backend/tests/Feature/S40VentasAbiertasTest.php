<?php

use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\Venta;
use App\Models\VentaItem;
use Illuminate\Support\Facades\DB;

/*
 * RF-06 / RF-12 / RF-14 (S-40; en el pedido S-37): el mostrador escanea a lo largo del día y cierra al
 * final; puede tener varias ventas abiertas (contado y crédito). Escanear con cantidad por código
 * del proveedor.
 */

beforeEach(fn () => sembrarDemo());

function abrirS40(array $datos = []): int
{
    return abrirVenta($datos);
}

function escanearS40(int $ventaId, string $codigo, array $extra = [])
{
    return test()->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => $codigo, 'metodo_captura' => 'escaneado', ...$extra]);
}

it('S-40 (a): el listado de abiertas trae contado y crédito con ítems vigentes y total; excluye cerradas; cada mostrador ve las suyas y el admin todas', function () {
    $ref = refS38('ABI-1', 4, 50000);
    [$c1, $c2, $c3, $c4] = codigosS38($ref);
    $cliente = Cliente::factory()->create(['nombre' => 'Taller Abierto']);

    actuarComo(Rol::Mostrador);
    $contado = abrirS40();
    escanearS40($contado, $c1)->assertCreated();
    $item = escanearS40($contado, $c2)->assertCreated()->json('venta_item_id');
    // Un ítem reversado no cuenta ni en ítems ni en total.
    $this->postJson("/api/ventas/{$contado}/reversos", ['tipo' => 'anulacion', 'motivo' => 'No era', 'venta_item_id' => $item])->assertOk();
    $credito = abrirS40(['forma_pago' => 'credito', 'cliente_id' => $cliente->id]);
    escanearS40($credito, $c3)->assertCreated();
    $cerrada = abrirS40();
    cerrarVenta($cerrada)->assertOk();

    $r = $this->getJson('/api/ventas?estado=abierta')->assertOk();
    $filas = collect($r->json('data'));
    expect($filas->pluck('id')->all())->toBe([$contado, $credito])
        ->and($filas[0])->toMatchArray(['forma_pago' => 'contado', 'items_count' => 1])
        ->and((float) $filas[0]['total'])->toBe(50000.0)
        ->and($filas[1]['cliente']['nombre'])->toBe('Taller Abierto')
        ->and($filas[1]['items_count'])->toBe(1);

    // Otro mostrador no las ve; el admin sí (todas).
    actuarComo(Rol::Mostrador);
    $otra = abrirS40();
    expect(collect($this->getJson('/api/ventas?estado=abierta')->json('data'))->pluck('id')->all())->toBe([$otra]);
    actuarComo(Rol::Admin);
    expect(collect($this->getJson('/api/ventas?estado=abierta')->json('data'))->pluck('id')->all())->toBe([$contado, $credito, $otra]);
});

it('S-40 (b): cantidad 5 por código de proveedor crea 5 ítems con unidades distintas y suma al total; con 3 disponibles, 422 y ninguno', function () {
    $ref = refS38('CANT-V', 6, 20000);
    actuarComo(Rol::Mostrador);
    $venta = abrirS40();

    $r = escanearS40($venta, 'CANT-V', ['cantidad' => 5])->assertCreated()
        ->assertJsonCount(5, 'items')
        ->assertJsonPath('totales.total', '100000.00');
    $unidades = collect($r->json('items'))->pluck('unidad.codigo_barras');
    expect($unidades->unique())->toHaveCount(5)
        ->and($r->json('item.id'))->toBe(collect($r->json('items'))->last()['id'])
        ->and(VentaItem::where('venta_id', $venta)->count())->toBe(5)
        ->and($ref->refresh()->existencia)->toBe(1);

    $pocas = refS38('CANT-P', 3, 20000);
    escanearS40($venta, 'CANT-P', ['cantidad' => 5])->assertUnprocessable()
        ->assertJsonPath('message', fn ($m) => str_starts_with($m, 'Solo hay 3 unidades disponibles de CANT-P'));
    expect(VentaItem::where('venta_id', $venta)->count())->toBe(5)
        ->and($pocas->refresh()->existencia)->toBe(3);
});

it('S-40 (c): cantidad mayor que 1 con una etiqueta, o 0, 51 o no entera: 422', function () {
    $ref = refS38('CANT-E', 2);
    [$a] = codigosS38($ref);
    actuarComo(Rol::Mostrador);
    $venta = abrirS40();

    escanearS40($venta, $a, ['cantidad' => 2])->assertUnprocessable()
        ->assertJsonPath('message', "Cada etiqueta es una unidad: {$a} se agrega de a una (cantidad 1).");
    foreach ([0, 51, 1.5, 'dos'] as $cantidad) {
        escanearS40($venta, 'CANT-E', ['cantidad' => $cantidad])->assertUnprocessable()->assertJsonValidationErrors('cantidad');
    }
    expect(VentaItem::where('venta_id', $venta)->count())->toBe(0);
    escanearS40($venta, $a, ['cantidad' => 1])->assertCreated();
});

it('S-40 (d): varias ventas abiertas de un usuario conviven y escanear en una no afecta a la otra', function () {
    $ref = refS38('CONV-1', 3, 30000);
    [$a, $b] = codigosS38($ref);
    $cliente = Cliente::factory()->create();
    actuarComo(Rol::Mostrador);
    $contado = abrirS40();
    $credito = abrirS40(['forma_pago' => 'credito', 'cliente_id' => $cliente->id]);

    escanearS40($contado, $a)->assertCreated();
    escanearS40($credito, $b)->assertCreated();
    escanearS40($credito, 'CONV-1')->assertCreated();

    expect(VentaItem::where('venta_id', $contado)->count())->toBe(1)
        ->and(VentaItem::where('venta_id', $credito)->count())->toBe(2);
    $this->getJson("/api/ventas/{$contado}")->assertJsonPath('totales.total', '30000.00');
    $this->getJson("/api/ventas/{$credito}")->assertJsonPath('totales.total', '60000.00');
});

it('S-40 (e): una venta abierta de un día anterior se cierra igual; a crédito vence desde el cierre', function () {
    $ref = refS38('VIEJA-1', 1, 40000);
    [$a] = codigosS38($ref);
    $cliente = Cliente::factory()->create(['plazo_dias' => 30]);
    actuarComo(Rol::Mostrador);
    $venta = abrirS40(['forma_pago' => 'credito', 'cliente_id' => $cliente->id]);
    escanearS40($venta, $a)->assertCreated();
    DB::table('ventas')->where('id', $venta)->update(['created_at' => now()->subDays(2)]);

    $r = cerrarVenta($venta)->assertOk()->assertJsonPath('estado', 'cerrada');
    expect($r->json('vence_el'))->toBe(Venta::find($venta)->cerrada_at->copy()->startOfDay()->addDays(30)->toDateString());
});
