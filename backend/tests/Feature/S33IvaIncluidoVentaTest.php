<?php

use App\Enums\Rol;
use App\Models\Cliente;
use App\Support\TotalesVenta;
use Illuminate\Support\Facades\DB;

/*
 * S-33 / S-15: el precio de venta INCLUYE IVA. El total es la suma de los ítems vigentes y la base y
 * el IVA incluido se extraen (base = precio / (1 + iva/100), redondeo al peso solo al final).
 */

beforeEach(fn () => sembrarDemo());

it('S-33: dos ítems de 119.000 y 59.500 al 19 %: total 178.500, base 150.000, IVA incluido 28.500', function () {
    $cliente = Cliente::factory()->create();
    [$ventaId, $items] = ventaCredito($cliente, [119000, 59500]);
    DB::table('venta_items')->whereIn('id', $items)->update(['iva_porcentaje' => 19]);

    actuarComo(Rol::Mostrador);
    $this->getJson("/api/ventas/{$ventaId}")->assertOk()
        ->assertJsonPath('totales.total', '178500.00')
        ->assertJsonPath('totales.base', '150000.00')
        ->assertJsonPath('totales.iva_incluido', '28500.00')
        ->assertJsonPath('totales.iva_porcentajes', [19]);

    // El total de la lista de ventas recientes es el mismo: no se suma IVA encima.
    $fila = collect($this->getJson('/api/ventas?estado=cerrada&todas=1')->json('data'))->firstWhere('id', $ventaId);
    expect((float) $fila['total'])->toBe(178500.0);

    // La cartera no cambia: el saldo es el total con IVA incluido.
    $this->getJson("/api/clientes/{$cliente->id}/cartera")->assertJsonPath('resumen.saldo', '178500.00');
});

it('S-33: criterio 1: una pieza de 119.000 da base 100.000 e IVA incluido 19.000', function () {
    $t = TotalesVenta::desglosar([['precio' => '119000.00', 'iva' => '19.00', 'vigente' => true]]);

    expect([$t->total, $t->base, $t->ivaIncluido])->toBe(['119000.00', '100000.00', '19000.00']);
});

it('S-33: con un ítem al 0 % y otro al 19 % se desglosa por ítem', function () {
    $t = TotalesVenta::desglosar([
        ['precio' => '50000.00', 'iva' => '0.00', 'vigente' => true],
        ['precio' => '119000.00', 'iva' => '19.00', 'vigente' => true],
    ]);

    expect([$t->total, $t->base, $t->ivaIncluido])->toBe(['169000.00', '150000.00', '19000.00'])
        ->and($t->porcentajes)->toBe([0.0, 19.0]);
});

it('S-33: las bases se suman sin redondear y se redondea al peso solo al final', function () {
    // 10.000 / 1,19 = 8.403,36… por ítem; tres ítems: 25.210,08… → 25.210 (por ítem redondeado daría 25.209).
    $t = TotalesVenta::desglosar(array_fill(0, 3, ['precio' => '10000.00', 'iva' => '19.00', 'vigente' => true]));

    expect([$t->total, $t->base, $t->ivaIncluido])->toBe(['30000.00', '25210.00', '4790.00']);
});

it('S-33: criterio 3: un ítem reversado no cuenta en base, IVA ni total', function () {
    $cliente = Cliente::factory()->create();
    [$ventaId, $items] = ventaCredito($cliente, [119000, 59500], false);
    DB::table('venta_items')->whereIn('id', $items)->update(['iva_porcentaje' => 19]);

    actuarComo(Rol::Mostrador);
    $this->postJson("/api/ventas/{$ventaId}/reversos", ['tipo' => 'anulacion', 'motivo' => 'Se equivocó', 'venta_item_id' => $items[1]])->assertOk();

    $this->getJson("/api/ventas/{$ventaId}")->assertOk()
        ->assertJsonPath('totales.total', '119000.00')
        ->assertJsonPath('totales.base', '100000.00')
        ->assertJsonPath('totales.iva_incluido', '19000.00');
    $fila = collect($this->getJson('/api/ventas?estado=abierta&todas=1')->json('data'))->firstWhere('id', $ventaId);
    expect((float) $fila['total'])->toBe(119000.0);
});

it('S-33: una venta antigua con IVA NULL no se desglosa y el total es la suma de los precios', function () {
    $cliente = Cliente::factory()->create();
    [$ventaId, $items] = ventaCredito($cliente, [30000, 20000]);
    DB::table('venta_items')->whereIn('id', $items)->update(['iva_porcentaje' => null]);

    actuarComo(Rol::Mostrador);
    $this->getJson("/api/ventas/{$ventaId}")->assertOk()
        ->assertJsonPath('totales.total', '50000.00')
        ->assertJsonPath('totales.base', '50000.00')
        ->assertJsonPath('totales.iva_incluido', '0.00')
        ->assertJsonPath('totales.items_sin_iva', 2)
        ->assertJsonPath('totales.iva_porcentajes', []);
});

it('S-33 (merge cesar + legri): el escaneo devuelve el ítem y los totales de la venta, para actualizarla sin pedirla otra vez', function () {
    actuarComo(Rol::Mostrador);
    $ventaId = $this->postJson('/api/ventas')->assertCreated()->json('id');
    $unidad = unidadDe('RN-4410');
    $precio = number_format((float) $unidad->referencia->precio_venta, 2, '.', '');

    $r = $this->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => $unidad->codigo_barras, 'metodo_captura' => 'escaneado'])
        ->assertCreated()
        ->assertJsonPath('item.unidad.codigo_barras', $unidad->codigo_barras)
        ->assertJsonPath('item.origen_codigo', 'etiqueta')
        ->assertJsonPath('totales.total', $precio);

    expect($r->json('totales'))->toBe($this->getJson("/api/ventas/{$ventaId}")->json('totales'));
});
