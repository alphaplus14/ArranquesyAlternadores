<?php

use App\Enums\Rol;
use App\Models\AjusteInventario;
use App\Models\AjusteItem;
use App\Models\Ingreso;
use App\Models\MovimientoInventario;
use App\Models\Referencia;
use App\Models\Unidad;
use Illuminate\Support\Facades\DB;

/*
 * RF-21 / RF-03 / RF-12 (S-39; en el pedido S-36): entrada por ajuste de inventario físico ("alta"):
 * unidades nuevas con código del local, sin ingreso ni costo; motivo "faltante" en salidas.
 */

beforeEach(fn () => sembrarDemo());

function entrada(array $datos)
{
    return test()->postJson('/api/ajustes/entradas', ['cantidad' => 1, 'motivo' => 'sobrante_inventario', 'metodo_captura' => 'escaneado', ...$datos]);
}

it('S-39 (a): entrada de 5 a una referencia con existencia 2: queda en 7, 5 unidades nuevas con código del local y 5 movimientos alta', function () {
    $ref = refS38('ALTA-1', 2);
    $ingresosAntes = Ingreso::count();
    $logistica = actuarComo(Rol::Logistica);

    $r = entrada(['referencia_id' => $ref->id, 'cantidad' => 5, 'metodo_captura' => 'digitado'])->assertCreated()
        ->assertJsonPath('tipo', 'alta')
        ->assertJsonPath('motivo', 'sobrante_inventario')
        ->assertJsonPath('usuario.id', $logistica->id)
        ->assertJsonCount(5, 'unidades');

    $ajusteId = $r->json('id');
    $nuevas = Unidad::where('ajuste_id', $ajusteId)->get();
    expect($ref->refresh()->existencia)->toBe(7)
        ->and($nuevas)->toHaveCount(5)
        ->and($nuevas->pluck('codigo_barras')->unique())->toHaveCount(5)
        ->and($nuevas->every(fn ($u) => str_starts_with($u->codigo_barras, config('etiquetas.prefijo')) && $u->estado->value === 'disponible' && $u->ingreso_item_id === null))->toBeTrue()
        ->and(collect($r->json('unidades'))->pluck('codigo_barras')->all())->toBe($nuevas->pluck('codigo_barras')->all());

    $movs = MovimientoInventario::where('tipo', 'alta')->where('origen_type', 'ajuste')->where('origen_id', $ajusteId)->get();
    expect($movs)->toHaveCount(5)
        ->and($movs->every(fn ($m) => $m->usuario_id === $logistica->id && $m->metodo_captura->value === 'digitado' && $m->created_at !== null))->toBeTrue()
        ->and(AjusteItem::where('ajuste_id', $ajusteId)->count())->toBe(5)
        // No es una compra: no crea ingresos.
        ->and(Ingreso::count())->toBe($ingresosAntes);
});

it('S-39 (b): motivo "otro" sin nota o un motivo que no es de entradas: 422', function () {
    $ref = refS38('ALTA-2', 0);
    actuarComo(Rol::Logistica);

    entrada(['referencia_id' => $ref->id, 'motivo' => 'otro'])->assertUnprocessable()->assertJsonValidationErrors('nota');
    entrada(['referencia_id' => $ref->id, 'motivo' => 'otro', 'nota' => 'Conteo de diciembre'])->assertCreated();
    entrada(['referencia_id' => $ref->id, 'motivo' => 'dano'])->assertUnprocessable()->assertJsonValidationErrors('motivo');
    entrada(['referencia_id' => $ref->id, 'motivo' => 'sin_factura'])->assertCreated();

    expect($ref->refresh()->existencia)->toBe(2);
});

it('S-39 (c): cantidad 0, negativa, mayor a 500 o no entera; referencia inexistente o inactiva: error', function () {
    $ref = refS38('ALTA-3', 1);
    $inactiva = refS38('ALTA-INA', 0);
    DB::table('referencias')->where('id', $inactiva->id)->update(['activo' => false]);
    actuarComo(Rol::Logistica);

    foreach ([0, -2, 501, 2.5, 'tres'] as $cantidad) {
        entrada(['referencia_id' => $ref->id, 'cantidad' => $cantidad])->assertUnprocessable()->assertJsonValidationErrors('cantidad');
    }
    entrada(['referencia_id' => 999999])->assertNotFound();
    entrada(['referencia_id' => $inactiva->id])->assertUnprocessable()
        ->assertJsonPath('message', 'La referencia ALTA-INA está inactiva: reactívela para darle entrada.');

    expect($ref->refresh()->existencia)->toBe(1)->and(AjusteInventario::count())->toBe(0);
});

it('S-39 (e): las unidades dadas de alta se venden por etiqueta o por código del proveedor, y van primero (sin etiqueta impresa)', function () {
    $ref = refS38('ALTA-V', 1);
    Unidad::where('referencia_id', $ref->id)->update(['etiqueta_impresa_at' => now()]);
    actuarComo(Rol::Logistica);
    $nuevas = collect(entrada(['referencia_id' => $ref->id, 'cantidad' => 2])->assertCreated()->json('unidades'))->pluck('codigo_barras');

    actuarComo(Rol::Mostrador);
    $ventaId = $this->postJson('/api/ventas')->assertCreated()->json('id');
    // Por código del proveedor: la de alta (sin etiqueta impresa) va antes que la etiquetada vieja.
    $this->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => 'ALTA-V', 'metodo_captura' => 'escaneado'])->assertCreated()
        ->assertJsonPath('unidad.codigo_barras', $nuevas[0]);
    // Por etiqueta.
    $this->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => $nuevas[1], 'metodo_captura' => 'escaneado'])->assertCreated();

    expect($ref->refresh()->existencia)->toBe(1);

});

it('S-39 (e) / criterio 4: la entrada sube la existencia y saca la referencia de "bajo mínimo"', function () {
    $ref = refS38('ALTA-M', 1);
    DB::table('referencias')->where('id', $ref->id)->update(['stock_minimo' => 3]);
    actuarComo(Rol::Logistica);

    $codigos = fn () => collect($this->getJson('/api/reportes/bajo-minimo?por_pagina=200')->assertOk()->json('data'))->pluck('codigo')->all();
    expect($codigos())->toContain('ALTA-M');
    entrada(['referencia_id' => $ref->id, 'cantidad' => 3])->assertCreated();
    expect($codigos())->not->toContain('ALTA-M');
});

it('S-39 (f) / S-44: mostrador y admin 403 en entradas y en la búsqueda de referencia; logística pasa', function () {
    $ref = refS38('ALTA-R', 0);

    actuarComo(Rol::Mostrador);
    entrada(['referencia_id' => $ref->id])->assertForbidden();
    $this->getJson('/api/ajustes/referencia?codigo=ALTA-R')->assertForbidden();

    actuarComo(Rol::Admin);
    entrada(['referencia_id' => $ref->id])->assertForbidden();
    $this->getJson('/api/ajustes/referencia?codigo=ALTA-R')->assertForbidden();

    actuarComo(Rol::Logistica);
    $this->getJson('/api/ajustes/referencia?codigo=ALTA-R')->assertOk()->assertJsonPath('referencias.0.id', $ref->id);
    entrada(['referencia_id' => $ref->id])->assertCreated();
});

it('S-39: la búsqueda de referencia resuelve el código exacto (varias = elegir), busca por texto (máx. 10) y dice si tiene precio', function () {
    $a = refS38('DUP-ALTA', 0);
    $b = refS38('DUP-ALTA', 0);
    DB::table('referencias')->where('id', $b->id)->update(['precio_venta' => 0]);
    actuarComo(Rol::Logistica);

    $r = $this->getJson('/api/ajustes/referencia?codigo=dup-alta')->assertOk()->assertJsonCount(2, 'referencias');
    expect(collect($r->json('referencias'))->pluck('tiene_precio', 'id')->all())->toBe([$a->id => true, $b->id => false]);
    $this->getJson('/api/ajustes/referencia?codigo=NO-HAY')->assertNotFound();

    foreach (range(1, 12) as $n) {
        refS38(sprintf('TXT-%02d', $n), 0);
    }
    $this->getJson('/api/ajustes/referencia?q=TXT')->assertOk()->assertJsonCount(10, 'referencias');
});

it('S-39 (g): el ajuste de alta es inmutable y no tiene PUT ni DELETE', function () {
    $ref = refS38('ALTA-G', 0);
    actuarComo(Rol::Logistica);
    $id = entrada(['referencia_id' => $ref->id, 'cantidad' => 2])->json('id');

    foreach (['putJson', 'patchJson', 'deleteJson'] as $verbo) {
        expect($this->{$verbo}("/api/ajustes/{$id}", ['cantidad' => 9])->status())->toBeIn([404, 405]);
    }
    expect(fn () => AjusteInventario::find($id)->update(['nota' => 'cambio']))->toThrow(Exception::class);
});

it('S-39 (h): el historial filtra por tipo alta y por motivo; compras y cartera no cambian', function () {
    $ref = refS38('ALTA-H', 0);
    actuarComo(Rol::Admin);
    $compras = $this->getJson('/api/reportes/compras')->json('totales');
    $cartera = $this->getJson('/api/cartera?solo_con_saldo=0')->json('totales');

    // S-44: las entradas las registra logística; el admin consulta el historial.
    $admin = auth()->user();
    actuarComo(Rol::Logistica);
    entrada(['referencia_id' => $ref->id, 'cantidad' => 3, 'motivo' => 'sin_factura', 'nota' => 'Caja sin documento'])->assertCreated();
    entrada(['referencia_id' => $ref->id, 'cantidad' => 1])->assertCreated();
    $this->actingAs($admin);

    $this->getJson('/api/ajustes?tipo=alta')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/ajustes?tipo=alta&motivo=sin_factura')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.cantidad', 3)
        ->assertJsonPath('data.0.nota', 'Caja sin documento')
        ->assertJsonPath('data.0.unidades.0.referencia.codigo_referencia', 'ALTA-H')
        ->assertJsonPath('data.0.unidades.0.reintegrable', false);

    expect($this->getJson('/api/reportes/compras')->json('totales'))->toBe($compras)
        ->and($this->getJson('/api/cartera?solo_con_saldo=0')->json('totales'))->toBe($cartera);
});

it('S-39 (j): una salida con motivo "Faltante de inventario físico" funciona como las demás', function () {
    $ref = refS38('FALT-1', 2);
    [$a] = codigosS38($ref);
    actuarComo(Rol::Logistica);

    $this->postJson('/api/ajustes/salidas', ['motivo' => 'faltante_inventario', 'codigos' => [['codigo' => $a, 'metodo_captura' => 'escaneado']]])
        ->assertCreated()->assertJsonPath('motivo', 'faltante_inventario');
    expect($ref->refresh()->existencia)->toBe(1);
    // No es un motivo de entrada.
    entrada(['referencia_id' => $ref->id, 'motivo' => 'faltante_inventario'])->assertUnprocessable();
});
