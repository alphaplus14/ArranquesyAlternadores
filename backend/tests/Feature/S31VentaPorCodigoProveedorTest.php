<?php

use App\Enums\Rol;
use App\Models\MovimientoInventario;
use App\Models\Proveedor;
use App\Models\Referencia;
use App\Models\Unidad;
use App\Models\VentaItem;
use Illuminate\Support\Facades\DB;

/*
 * RF-03 / RF-06 / RF-12 / S-25 / S-31 (modifica la regla 3): la venta de mostrador acepta la
 * etiqueta del local o el código del proveedor/fabricante de la referencia.
 */

beforeEach(fn () => sembrarDemo());

/** Referencia activa con $unidades unidades disponibles (ingreso confirmado). */
function referenciaConUnidades(string $codigo, int $unidades, array $atributos = []): Referencia
{
    $ref = Referencia::factory()->create(['codigo_referencia' => $codigo, ...$atributos]);
    if ($unidades > 0) {
        ingresar($ref, $unidades);
    }

    return $ref->refresh();
}

function abrirVenta(): int
{
    return test()->postJson('/api/ventas')->assertCreated()->json('id');
}

function escanearEn(int $ventaId, string $codigo, array $extra = [])
{
    return test()->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => $codigo, 'metodo_captura' => 'escaneado', ...$extra]);
}

function unidadesDe(Referencia $ref)
{
    return Unidad::where('referencia_id', $ref->id)->orderBy('id')->get();
}

it('S-31 (a): la etiqueta del local se vende igual que antes, con origen_codigo = etiqueta', function () {
    $ref = referenciaConUnidades('ETQ-1', 1);
    $unidad = unidadesDe($ref)->first();

    $mostrador = actuarComo(Rol::Mostrador);
    $ventaId = abrirVenta();
    $r = escanearEn($ventaId, $unidad->codigo_barras)->assertCreated()
        ->assertJsonPath('origen_codigo', 'etiqueta')
        ->assertJsonPath('unidad.codigo_barras', $unidad->codigo_barras);

    $item = VentaItem::find($r->json('venta_item_id'));
    expect($item->origen_codigo->value)->toBe('etiqueta')
        ->and($item->usuario_id)->toBe($mostrador->id)
        ->and(MovimientoInventario::where('tipo', 'venta')->where('unidad_id', $unidad->id)->value('origen_codigo')->value)->toBe('etiqueta')
        ->and($ref->refresh()->existencia)->toBe(0);
});

it('S-31 (b): el código del proveedor descarga una unidad, baja la existencia y registra origen, usuario y método', function () {
    $ref = referenciaConUnidades('0697287220005', 2);

    $mostrador = actuarComo(Rol::Mostrador);
    $ventaId = abrirVenta();
    // Sin distinguir mayúsculas ni espacios en los extremos.
    $r = escanearEn($ventaId, '  0697287220005 ', ['metodo_captura' => 'digitado'])->assertCreated()
        ->assertJsonPath('origen_codigo', 'referencia')
        ->assertJsonPath('referencia.id', $ref->id)
        ->assertJsonPath('referencia.existencia', 1);

    $item = VentaItem::find($r->json('venta_item_id'));
    $mov = MovimientoInventario::where('tipo', 'venta')->where('unidad_id', $item->unidad_id)->first();
    expect($r->json('unidad.codigo_barras'))->toBe($item->unidad->codigo_barras)
        ->and($item->origen_codigo->value)->toBe('referencia')
        ->and($item->metodo_captura->value)->toBe('digitado')
        ->and($item->usuario_id)->toBe($mostrador->id)
        ->and($mov->origen_codigo->value)->toBe('referencia')
        ->and($mov->metodo_captura->value)->toBe('digitado')
        ->and($mov->usuario_id)->toBe($mostrador->id)
        ->and($item->unidad->estado->value)->toBe('vendida')
        ->and($ref->refresh()->existencia)->toBe(1);

    // La ficha de la venta muestra el origen.
    $this->getJson("/api/ventas/{$ventaId}")->assertOk()->assertJsonPath('items.0.origen_codigo', 'referencia');

    // También con letras: mayúsculas indistintas.
    $conLetras = referenciaConUnidades('AB-77X', 1);
    escanearEn($ventaId, 'ab-77x')->assertCreated()->assertJsonPath('referencia.id', $conLetras->id);
});

it('S-31 (c): primero las unidades sin etiqueta impresa, la más antigua; luego las etiquetadas, la más antigua', function () {
    $ref = referenciaConUnidades('PREF-1', 3);
    [$u1, $u2, $u3] = unidadesDe($ref)->all();

    // Imprimir la etiqueta de la primera (la más antigua) la marca como etiquetada.
    actuarComo(Rol::Logistica);
    $this->post('/api/etiquetas', ['unidad_ids' => [$u1->id], 'tamano' => 'grande'])->assertOk();
    expect($u1->refresh()->etiqueta_impresa_at)->not->toBeNull()
        ->and($u2->refresh()->etiqueta_impresa_at)->toBeNull();

    actuarComo(Rol::Mostrador);
    $ventaId = abrirVenta();
    expect(escanearEn($ventaId, 'PREF-1')->assertCreated()->json('unidad.id'))->toBe($u2->id)
        ->and(escanearEn($ventaId, 'PREF-1')->assertCreated()->json('unidad.id'))->toBe($u3->id)
        ->and(escanearEn($ventaId, 'PREF-1')->assertCreated()->json('unidad.id'))->toBe($u1->id);

    // Con varias etiquetadas, la más antigua de ellas.
    $otra = referenciaConUnidades('PREF-2', 2);
    Unidad::where('referencia_id', $otra->id)->update(['etiqueta_impresa_at' => now()]);
    expect(escanearEn($ventaId, 'PREF-2')->assertCreated()->json('unidad.id'))->toBe(unidadesDe($otra)->first()->id);
});

it('S-31 (d): un código en dos proveedores pide elegir; con referencia_id descarga la elegida; uno ajeno es 422', function () {
    $a = referenciaConUnidades('DUP-9', 1, ['proveedor_id' => Proveedor::factory()->create(['nombre' => 'Prov A'])->id]);
    $b = referenciaConUnidades('DUP-9', 2, ['proveedor_id' => Proveedor::factory()->create(['nombre' => 'Prov B'])->id]);
    $ajena = referenciaConUnidades('OTRO-1', 1);

    actuarComo(Rol::Mostrador);
    $ventaId = abrirVenta();
    $r = escanearEn($ventaId, 'DUP-9')->assertUnprocessable()
        ->assertJsonPath('tipo', 'elegir_referencia')
        ->assertJsonPath('codigo', 'DUP-9');
    expect(collect($r->json('referencias'))->pluck('id')->all())->toBe([$a->id, $b->id])
        ->and($r->json('referencias.1'))->toMatchArray(['codigo_referencia' => 'DUP-9', 'proveedor' => 'Prov B', 'existencia' => 2])
        ->and(VentaItem::where('venta_id', $ventaId)->count())->toBe(0);

    escanearEn($ventaId, 'DUP-9', ['referencia_id' => $b->id])->assertCreated()
        ->assertJsonPath('referencia.id', $b->id)
        ->assertJsonPath('origen_codigo', 'referencia');
    expect($b->refresh()->existencia)->toBe(1)->and($a->refresh()->existencia)->toBe(1);

    escanearEn($ventaId, 'DUP-9', ['referencia_id' => $ajena->id])->assertUnprocessable()
        ->assertJsonPath('message', 'La referencia elegida no corresponde al código DUP-9.');
    expect($ajena->refresh()->existencia)->toBe(1);
});

it('S-31 (e): sin unidades disponibles o con precio 0 responde 422 con un mensaje claro y no descuenta nada', function () {
    $vacia = referenciaConUnidades('VACIA-1', 0, ['proveedor_id' => Proveedor::factory()->create(['nombre' => 'Prov Vacío'])->id]);
    $sinPrecio = referenciaConUnidades('SINP-1', 1);
    DB::table('referencias')->where('id', $sinPrecio->id)->update(['precio_venta' => 0]);

    actuarComo(Rol::Mostrador);
    $ventaId = abrirVenta();
    escanearEn($ventaId, 'VACIA-1')->assertUnprocessable()
        ->assertJsonPath('message', 'La referencia VACIA-1 (Prov Vacío) no tiene unidades disponibles.');
    escanearEn($ventaId, 'SINP-1')->assertUnprocessable()
        ->assertJsonPath('message', 'La referencia SINP-1 no tiene precio de venta; pídale al administrador que se lo asigne.');

    expect(VentaItem::where('venta_id', $ventaId)->count())->toBe(0)
        ->and($sinPrecio->refresh()->existencia)->toBe(1)
        ->and(unidadesDe($sinPrecio)->first()->estado->value)->toBe('disponible');
});

it('S-31 (f): un código inexistente o de una referencia desactivada responde 404 como hoy', function () {
    $inactiva = referenciaConUnidades('INACT-1', 1);
    DB::table('referencias')->where('id', $inactiva->id)->update(['activo' => false]);

    actuarComo(Rol::Mostrador);
    $ventaId = abrirVenta();
    escanearEn($ventaId, 'NO-EXISTE-123')->assertNotFound()
        ->assertJsonPath('message', 'No existe una unidad con el código NO-EXISTE-123.');
    escanearEn($ventaId, 'INACT-1')->assertNotFound()
        ->assertJsonPath('message', 'No existe una unidad con el código INACT-1.');
    expect($inactiva->refresh()->existencia)->toBe(1);
});

it('S-31 (g) / S-25: el código del fabricante también resuelve la referencia', function () {
    $ref = referenciaConUnidades('FAB-REF', 1);
    DB::table('referencias')->where('id', $ref->id)->update(['codigo_fabricante' => '7701234567890']);

    actuarComo(Rol::Mostrador);
    escanearEn(abrirVenta(), '7701234567890')->assertCreated()
        ->assertJsonPath('referencia.id', $ref->id)
        ->assertJsonPath('origen_codigo', 'referencia');
    expect($ref->refresh()->existencia)->toBe(0);
});

it('S-31 (i): reversar un ítem vendido por referencia devuelve la unidad a disponible', function () {
    $ref = referenciaConUnidades('REV-1', 1);

    actuarComo(Rol::Mostrador);
    $ventaId = abrirVenta();
    $itemId = escanearEn($ventaId, 'REV-1')->assertCreated()->json('venta_item_id');
    expect($ref->refresh()->existencia)->toBe(0);

    $this->postJson("/api/ventas/{$ventaId}/reversos", ['tipo' => 'anulacion', 'motivo' => 'Se equivocó de pieza', 'venta_item_id' => $itemId])->assertOk();

    expect($ref->refresh()->existencia)->toBe(1)
        ->and(VentaItem::find($itemId)->unidad->estado->value)->toBe('disponible');
});

it('S-31 (j) / S-34: salidas y devoluciones ya no rechazan el código de una referencia; un código que no es de nada sigue igual', function () {
    $ref = referenciaConUnidades('AJ-REF', 1, ['proveedor_id' => Proveedor::factory()->create(['nombre' => 'Prov Ajuste'])->id]);

    actuarComo(Rol::Logistica);
    // S-34: la salida asigna una unidad de la referencia.
    $this->getJson('/api/ajustes/unidad?tipo=baja&codigo=AJ-REF')->assertOk()
        ->assertJsonPath('origen_codigo', 'referencia')
        ->assertJsonPath('referencia_id', $ref->id);
    // S-34: la devolución pide elegir la venta (no hay ventas cerradas de esta referencia).
    $this->getJson('/api/devoluciones/consulta?codigo=AJ-REF')->assertUnprocessable()
        ->assertJsonPath('message', 'No hay ventas cerradas sin devolver de la referencia AJ-REF.');

    $this->getJson('/api/ajustes/unidad?tipo=baja&codigo=NADA-1')->assertUnprocessable()
        ->assertJsonPath('message', 'No existe una unidad con el código NADA-1.');
    $this->getJson('/api/devoluciones/consulta?codigo=NADA-1')->assertNotFound()
        ->assertJsonPath('message', 'No existe una unidad con el código NADA-1.');

    expect($ref->refresh()->existencia)->toBe(1);
});
