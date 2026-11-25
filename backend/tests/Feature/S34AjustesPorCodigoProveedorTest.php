<?php

use App\Enums\Rol;
use App\Models\AjusteInventario;
use App\Models\AjusteItem;
use App\Models\Cliente;
use App\Models\Proveedor;
use App\Models\Referencia;
use App\Models\Unidad;
use Illuminate\Support\Facades\DB;

/*
 * RF-21 / RF-07 / RF-12 (S-34, ajusta S-27 y S-31): salidas con el código del proveedor o del
 * fabricante (el sistema asigna la unidad) y devoluciones con elección explícita de la venta.
 */

beforeEach(fn () => sembrarDemo());

function refS34(string $codigo, int $unidades, array $atributos = []): Referencia
{
    $ref = Referencia::factory()->create(['codigo_referencia' => $codigo, ...$atributos]);
    if ($unidades > 0) {
        ingresar($ref, $unidades);
    }

    return $ref->refresh();
}

function unidadesS34(Referencia $ref)
{
    return Unidad::where('referencia_id', $ref->id)->orderBy('id')->get();
}

function salida(array $filas, string $motivo = 'dano')
{
    return test()->postJson('/api/ajustes/salidas', ['motivo' => $motivo, 'codigos' => $filas]);
}

/** Vende las unidades (por etiqueta) en una venta y la cierra si se pide. Deja la sesión que había. */
function venderS34(array $codigos, bool $cerrar = true, ?Cliente $cliente = null): int
{
    $anterior = auth()->user();
    actuarComo(Rol::Mostrador);
    $ventaId = abrirVenta($cliente ? ['forma_pago' => 'credito', 'cliente_id' => $cliente->id] : []);
    foreach ($codigos as $c) {
        test()->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => $c, 'metodo_captura' => 'escaneado'])->assertCreated();
    }
    if ($cerrar) {
        test()->postJson("/api/ventas/{$ventaId}/cerrar")->assertOk();
    }
    if ($anterior) {
        test()->actingAs($anterior);
    }

    return $ventaId;
}

it('S-34 (a): una salida por código de proveedor asigna la unidad sin etiqueta impresa más antigua y guarda origen referencia', function () {
    $ref = refS34('SAL-1', 3);
    [$u1, $u2] = unidadesS34($ref)->all();
    $u1->forceFill(['etiqueta_impresa_at' => now()])->save();

    actuarComo(Rol::Logistica);
    $this->getJson('/api/ajustes/unidad?tipo=baja&codigo=sal-1')->assertOk()
        ->assertJsonPath('unidades.0.id', $u2->id)
        ->assertJsonPath('unidades.0.codigo_barras', $u2->codigo_barras)
        ->assertJsonPath('unidades.0.origen_codigo', 'referencia')
        ->assertJsonPath('unidades.0.referencia.codigo_referencia', 'SAL-1');

    // Una etiqueta del local sigue igual, con origen etiqueta.
    $this->getJson("/api/ajustes/unidad?tipo=baja&codigo={$u1->codigo_barras}")->assertOk()->assertJsonPath('unidades.0.origen_codigo', 'etiqueta');

    $ajusteId = salida([['codigo' => 'SAL-1', 'metodo_captura' => 'digitado', 'origen_codigo' => 'referencia', 'unidad_codigo' => $u2->codigo_barras]])
        ->assertCreated()
        ->assertJsonPath('unidades.0.codigo_barras', $u2->codigo_barras)
        ->assertJsonPath('unidades.0.origen_codigo', 'referencia')
        ->assertJsonPath('unidades.0.metodo_captura', 'digitado')
        ->json('id');

    expect(AjusteItem::where('ajuste_id', $ajusteId)->first()->origen_codigo->value)->toBe('referencia')
        ->and($u2->refresh()->estado->value)->toBe('retirada')
        ->and($ref->refresh()->existencia)->toBe(2);
    // El historial lo conserva.
    $this->getJson('/api/ajustes?tipo=baja')->assertJsonPath('data.0.unidades.0.origen_codigo', 'referencia');
});

it('S-34 (b): un lote que repite el código toma unidades distintas; con una sola unidad, la segunda lectura no tiene unidades', function () {
    $ref = refS34('SAL-2', 2);
    [$u1, $u2] = unidadesS34($ref)->all();

    actuarComo(Rol::Logistica);
    $this->getJson('/api/ajustes/unidad?tipo=baja&codigo=SAL-2')->assertOk()->assertJsonPath('unidades.0.id', $u1->id);
    $this->getJson("/api/ajustes/unidad?tipo=baja&codigo=SAL-2&excluir[]={$u1->id}")->assertOk()->assertJsonPath('unidades.0.id', $u2->id);

    // Sin unidad_codigo el registro también asigna unidades distintas.
    salida([
        ['codigo' => 'SAL-2', 'metodo_captura' => 'escaneado', 'origen_codigo' => 'referencia'],
        ['codigo' => 'SAL-2', 'metodo_captura' => 'escaneado', 'origen_codigo' => 'referencia'],
    ])->assertCreated()->assertJsonCount(2, 'unidades');
    expect($ref->refresh()->existencia)->toBe(0);

    $sola = refS34('SAL-3', 1);
    $unica = unidadesS34($sola)->first();
    $this->getJson("/api/ajustes/unidad?tipo=baja&codigo=SAL-3&excluir[]={$unica->id}")->assertUnprocessable()
        ->assertJsonPath('message', "La referencia SAL-3 ({$sola->proveedor->nombre}) no tiene unidades disponibles.");
    salida([
        ['codigo' => 'SAL-3', 'metodo_captura' => 'escaneado', 'origen_codigo' => 'referencia'],
        ['codigo' => 'SAL-3', 'metodo_captura' => 'escaneado', 'origen_codigo' => 'referencia'],
    ])->assertUnprocessable();
    expect($sola->refresh()->existencia)->toBe(1);
});

it('S-34 (c): un código en dos referencias pide elegir; con referencia_id resuelve', function () {
    $a = refS34('DUP-S', 1, ['proveedor_id' => Proveedor::factory()->create(['nombre' => 'Prov A'])->id]);
    $b = refS34('DUP-S', 1, ['proveedor_id' => Proveedor::factory()->create(['nombre' => 'Prov B'])->id]);

    actuarComo(Rol::Logistica);
    $r = $this->getJson('/api/ajustes/unidad?tipo=baja&codigo=DUP-S')->assertUnprocessable()
        ->assertJsonPath('tipo', 'elegir_referencia')
        ->assertJsonPath('message', 'El código DUP-S está en varias referencias: elija cuál se retira.');
    expect(collect($r->json('referencias'))->pluck('id')->all())->toBe([$a->id, $b->id])
        ->and($r->json('referencias.1'))->toMatchArray(['proveedor' => 'Prov B', 'existencia' => 1]);

    $this->getJson("/api/ajustes/unidad?tipo=baja&codigo=DUP-S&referencia_id={$b->id}")->assertOk()
        ->assertJsonPath('unidades.0.referencia_id', $b->id);
});

it('S-34 (d): referencia sin unidades, desactivada o inexistente: error claro', function () {
    $vacia = refS34('VAC-S', 0, ['proveedor_id' => Proveedor::factory()->create(['nombre' => 'Prov V'])->id]);
    $inactiva = refS34('INA-S', 1);
    DB::table('referencias')->where('id', $inactiva->id)->update(['activo' => false]);

    actuarComo(Rol::Logistica);
    $this->getJson('/api/ajustes/unidad?tipo=baja&codigo=VAC-S')->assertUnprocessable()
        ->assertJsonPath('message', 'La referencia VAC-S (Prov V) no tiene unidades disponibles.');
    $this->getJson('/api/ajustes/unidad?tipo=baja&codigo=INA-S')->assertUnprocessable()
        ->assertJsonPath('message', 'No existe una unidad con el código INA-S.');
    $this->getJson('/api/ajustes/unidad?tipo=baja&codigo=NADA-S')->assertUnprocessable()
        ->assertJsonPath('message', 'No existe una unidad con el código NADA-S.')
        ->assertJsonPath('codigo', 'NADA-S');
    // Los reintegros siguen exigiendo la etiqueta.
    $this->getJson('/api/ajustes/unidad?tipo=reintegro&codigo=INA-S')->assertUnprocessable()
        ->assertJsonPath('message', 'No existe una unidad con el código INA-S.');
});

it('S-34 (e): el lote falla completo si la unidad asignada se vendió entre la verificación y el registro, y dice cuál', function () {
    $ref = refS34('ATO-1', 2);
    [$u1, $u2] = unidadesS34($ref)->all();

    actuarComo(Rol::Logistica);
    $asignada = $this->getJson('/api/ajustes/unidad?tipo=baja&codigo=ATO-1')->assertOk()->json('unidades.0.codigo_barras');
    expect($asignada)->toBe($u1->codigo_barras);

    // Otro usuario la vende en medio.
    venderS34([$u1->codigo_barras]);

    salida([
        ['codigo' => $u2->codigo_barras, 'metodo_captura' => 'escaneado'],
        ['codigo' => 'ATO-1', 'metodo_captura' => 'escaneado', 'origen_codigo' => 'referencia', 'unidad_codigo' => $asignada],
    ])->assertUnprocessable()
        ->assertJsonPath('message', "La unidad {$asignada} está vendida; no se puede retirar.")
        ->assertJsonPath('codigo', $asignada);

    expect(AjusteInventario::count())->toBe(0)
        ->and($u2->refresh()->estado->value)->toBe('disponible');

    // Una unidad asignada que no es de la referencia leída tampoco entra.
    $otra = unidadDe('RN-4410');
    salida([['codigo' => 'ATO-1', 'metodo_captura' => 'escaneado', 'origen_codigo' => 'referencia', 'unidad_codigo' => $otra->codigo_barras]])
        ->assertUnprocessable()->assertJsonPath('message', "La unidad {$otra->codigo_barras} no corresponde al código ATO-1.");
});

it('S-34 (f): la consulta de devolución con código de proveedor lista ventas cerradas candidatas, sin reversadas ni abiertas, máx. 20', function () {
    $ref = refS34('DEV-1', 25);
    $codigos = unidadesS34($ref)->pluck('codigo_barras');
    $cliente = Cliente::factory()->create(['nombre' => 'Taller Uno']);

    $vieja = venderS34($codigos->slice(0, 1)->all(), true, $cliente);
    DB::table('ventas')->where('id', $vieja)->update(['cerrada_at' => now()->subDays(3)]);
    $grande = venderS34($codigos->slice(1, 21)->all());
    $abierta = venderS34($codigos->slice(22, 1)->all(), false);
    $conReverso = venderS34($codigos->slice(23, 1)->all());
    DB::table('ventas')->where('id', $conReverso)->update(['cerrada_at' => now()->addMinute()]);

    actuarComo(Rol::Mostrador);
    $itemRev = DB::table('venta_items')->where('venta_id', $conReverso)->value('id');
    $this->postJson("/api/ventas/{$conReverso}/reversos", ['tipo' => 'devolucion', 'motivo' => 'Ya devuelta', 'venta_item_id' => $itemRev])->assertOk();

    actuarComo(Rol::Logistica);
    // S-38: agrupadas por (venta, referencia, precio): la venta grande es UN grupo de 21 unidades.
    $r = $this->getJson('/api/devoluciones/consulta?codigo=dev-1')->assertOk()
        ->assertJsonPath('tipo', 'elegir_venta')
        ->assertJsonCount(2, 'ventas')
        ->assertJsonPath('ventas.0.venta_id', $grande)
        ->assertJsonPath('ventas.0.cantidad_disponible', 21)
        ->assertJsonPath('ventas.1.venta_id', $vieja);
    $ventas = collect($r->json('ventas'));
    expect($ventas->pluck('venta_id'))->not->toContain($abierta)
        ->and($ventas->pluck('venta_id'))->not->toContain($conReverso)
        ->and($ventas->first())->toHaveKeys(['venta_id', 'cerrada_at', 'cliente', 'forma_pago', 'referencia', 'precio_unitario', 'cantidad_disponible'])
        ->and($ventas->first()['cerrada_at'])->toEndWith('Z');

    // Filtrar por n.º de venta: la vieja, a crédito, con su cliente.
    $this->getJson("/api/devoluciones/consulta?codigo=DEV-1&venta={$vieja}")->assertOk()
        ->assertJsonCount(1, 'ventas')
        ->assertJsonPath('ventas.0.forma_pago', 'credito')
        ->assertJsonPath('ventas.0.cliente.nombre', 'Taller Uno');
});

it('S-34 (g): devolver el ítem elegido lo reversa: la unidad vuelve a disponible y baja el saldo de ESE cliente', function () {
    $ref = refS34('DEV-2', 2);
    [$u1, $u2] = unidadesS34($ref)->all();
    $uno = Cliente::factory()->create();
    $otro = Cliente::factory()->create();
    venderS34([$u1->codigo_barras], true, $uno);
    venderS34([$u2->codigo_barras], true, $otro);

    actuarComo(Rol::Logistica);
    $candidata = collect($this->getJson('/api/devoluciones/consulta?codigo=DEV-2')->json('ventas'))->firstWhere('cliente.id', $uno->id);
    $saldoOtro = fn () => $this->getJson("/api/clientes/{$otro->id}/cartera");

    $this->postJson('/api/devoluciones', ['codigo' => 'DEV-2', 'venta_item_id' => $candidata['venta_item_id'], 'motivo' => 'No le sirvió', 'metodo_captura' => 'digitado'])
        ->assertCreated();

    actuarComo(Rol::Admin);
    expect($u1->refresh()->estado->value)->toBe('disponible')
        ->and($u2->refresh()->estado->value)->toBe('vendida')
        ->and($this->getJson("/api/clientes/{$uno->id}/cartera")->json('resumen.saldo'))->toBe('0.00')
        ->and($saldoOtro()->json('resumen.saldo'))->toBe(number_format((float) $ref->precio_venta, 2, '.', ''))
        ->and($ref->refresh()->existencia)->toBe(1);
});

it('S-34 (h): venta_item_id de una venta abierta, ya reversado, inexistente o de otro código: error', function () {
    $ref = refS34('DEV-3', 2);
    [$u1, $u2] = unidadesS34($ref)->all();
    $abierta = venderS34([$u1->codigo_barras], false);
    $cerrada = venderS34([$u2->codigo_barras]);
    $itemAbierta = DB::table('venta_items')->where('venta_id', $abierta)->value('id');
    $itemCerrada = DB::table('venta_items')->where('venta_id', $cerrada)->value('id');
    $devolver = fn ($item, $codigo = 'DEV-3') => $this->postJson('/api/devoluciones', ['codigo' => $codigo, 'venta_item_id' => $item, 'motivo' => 'Prueba', 'metodo_captura' => 'escaneado']);

    actuarComo(Rol::Logistica);
    $devolver($itemAbierta)->assertUnprocessable()
        ->assertJsonPath('message', "La unidad {$u1->codigo_barras} está en la venta abierta #{$abierta}: anule el ítem en la venta.");
    $devolver(999999)->assertNotFound()->assertJsonPath('message', 'No existe el ítem de venta #999999.');
    $devolver($itemCerrada, 'RN-4410')->assertUnprocessable()->assertJsonPath('message', 'El ítem elegido no corresponde al código RN-4410.');
    $devolver($itemCerrada)->assertCreated();
    $devolver($itemCerrada)->assertUnprocessable()
        ->assertJsonPath('message', "La unidad {$u2->codigo_barras} de la venta #{$cerrada} ya fue devuelta o anulada.");

    // Sin venta_item_id, un código de proveedor no se devuelve a ciegas.
    $this->postJson('/api/devoluciones', ['codigo' => 'DEV-3', 'motivo' => 'Prueba', 'metodo_captura' => 'escaneado'])
        ->assertUnprocessable()->assertJsonPath('message', 'El código DEV-3 es de una referencia: elija la venta que devuelve.');
});

it('S-34 (i): mostrador sigue sin acceso a salidas y devoluciones', function () {
    refS34('ROL-1', 1);

    actuarComo(Rol::Mostrador);
    $this->getJson('/api/ajustes/unidad?tipo=baja&codigo=ROL-1')->assertForbidden();
    $this->postJson('/api/ajustes/salidas', ['motivo' => 'dano', 'codigos' => [['codigo' => 'ROL-1', 'metodo_captura' => 'escaneado']]])->assertForbidden();
    $this->getJson('/api/devoluciones/consulta?codigo=ROL-1')->assertForbidden();
    $this->postJson('/api/devoluciones', ['codigo' => 'ROL-1', 'venta_item_id' => 1, 'motivo' => 'x', 'metodo_captura' => 'escaneado'])->assertForbidden();
});
