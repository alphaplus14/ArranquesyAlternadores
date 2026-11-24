<?php

use App\Enums\FormaPago;
use App\Enums\MetodoCaptura;
use App\Enums\Rol;
use App\Enums\TipoReverso;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\CodigoUniversal;
use App\Models\Referencia;
use App\Models\Unidad;
use App\Models\User;
use App\Models\Venta;
use App\Services\ReversoService;
use App\Services\VentaService;
use Illuminate\Support\Facades\DB;

/*
 * RF-23 / S-45: panel de gestión del administrador. Datos pequeños y controlados: precio 119.000
 * con IVA 19 % (base 100.000) y costo 50.000 salvo que se diga otra cosa.
 */

/** Referencia con `unidades` compradas a `costo` (línea sin IVA, TotalesIngreso). */
function refPanel(array $attrs = [], int $unidades = 0, int $costo = 50000, array $encabezado = []): Referencia
{
    $ref = Referencia::factory()->create(['precio_venta' => 119000, 'iva_venta_porcentaje' => 19, ...$attrs]);
    if ($unidades > 0) {
        compra($ref, $unidades, $costo, $encabezado);
    }

    return $ref->refresh();
}

/**
 * Vende en una sola venta. Cada línea: [Referencia, cantidad, precio libre (solo crédito) | null,
 * método]. Opciones: usuario, credito, cerrar (true), cerrada_at (UTC).
 */
function venderPanel(array $lineas, array $opc = []): Venta
{
    $usuario = $opc['usuario'] ?? User::factory()->rol(Rol::Mostrador)->create();
    $servicio = app(VentaService::class);
    $credito = $opc['credito'] ?? false;
    $venta = $servicio->abrir($usuario, $credito ? FormaPago::Credito : FormaPago::Contado, $credito ? Cliente::factory()->create()->id : null);

    foreach ($lineas as $linea) {
        [$ref, $n] = $linea;
        for ($i = 0; $i < $n; $i++) {
            $codigo = Unidad::where('referencia_id', $ref->id)->where('estado', 'disponible')->orderBy('id')->value('codigo_barras');
            $item = $servicio->escanear($venta, $codigo, $linea[3] ?? MetodoCaptura::Escaneado, $usuario);
            if (($linea[2] ?? null) !== null) {
                $servicio->cambiarPrecio($venta, $item, $linea[2], $usuario);
            }
        }
    }

    if ($opc['cerrar'] ?? true) {
        $venta = $servicio->cerrar($venta);
        if (isset($opc['cerrada_at'])) {
            DB::table('ventas')->where('id', $venta->id)->update(['cerrada_at' => $opc['cerrada_at']]);
        }
    }

    return $venta->refresh();
}

function reversarItem(Venta $venta, TipoReverso $tipo, ?int $itemId = null): void
{
    $itemId ??= $venta->items()->orderByDesc('id')->value('id');
    app(ReversoService::class)->reversar($venta, $tipo, 'Prueba del panel', $itemId, User::factory()->rol(Rol::Mostrador)->create());
}

/** GET del panel como admin, siempre recalculado salvo que se pida lo contrario. */
function panel(string $informe, array $params = [], bool $refrescar = true)
{
    return test()->getJson('/api/panel/'.$informe.'?'.http_build_query($params + ($refrescar ? ['refrescar' => 1] : [])));
}

beforeEach(fn () => actuarComo(Rol::Admin));

it('S-45 (1): ventas por día excluye abiertas y reversados, separa contado y crédito y una venta de las 23:30 en Bogotá cae en su día local', function () {
    $ref = refPanel(unidades: 5);
    venderPanel([[$ref, 1]], ['cerrada_at' => '2026-11-10 15:00:00']);                    // 10 a. m. del 10
    venderPanel([[$ref, 1]], ['credito' => true, 'cerrada_at' => '2026-11-11 04:30:00']);   // 11:30 p. m. del 10 en Bogotá
    venderPanel([[$ref, 1]], ['cerrar' => false]);                                          // abierta: no cuenta
    $reversada = venderPanel([[$ref, 1]], ['cerrada_at' => '2026-11-10 16:00:00']);
    reversarItem($reversada, TipoReverso::Devolucion);

    $r = panel('ventas-por-dia', ['desde' => '2026-11-09', 'hasta' => '2026-11-11'])->assertOk();

    expect($r->json('serie'))->toHaveCount(3)
        ->and($r->json('serie.1'))->toMatchArray(['dia' => '2026-11-10', 'contado' => '119000.00', 'credito' => '119000.00', 'total' => '238000.00', 'unidades' => 2, 'ventas' => 2])
        ->and($r->json('serie.0.total'))->toBe('0.00')
        ->and($r->json('serie.2.total'))->toBe('0.00')
        ->and($r->json('totales.total'))->toBe('238000.00');
});

it('S-45 (2): más vendidos ordena por unidades, valor y margen; un reverso no cuenta; la clase ABC es correcta', function () {
    $a = refPanel(['precio_venta' => 119000], 5, 50000);   // base 100.000, margen 50.000 c/u
    $b = refPanel(['precio_venta' => 595000], 2, 100000);  // base 500.000, margen 400.000 c/u
    $c = refPanel(['precio_venta' => 59500], 4, 45000);    // base 50.000, margen 5.000 c/u
    $d = refPanel(['precio_venta' => 11900], 1, 5000);     // base 10.000, margen 5.000
    $venta = venderPanel([[$a, 5], [$b, 2], [$c, 4], [$d, 1]], ['cerrar' => false]);
    reversarItem($venta, TipoReverso::Anulacion, $venta->items()->whereHas('unidad', fn ($q) => $q->where('referencia_id', $c->id))->value('id'));
    app(VentaService::class)->cerrar($venta);

    $porUnidades = panel('mas-vendidos', ['orden' => 'unidades'])->assertOk();
    expect(collect($porUnidades->json('filas'))->pluck('referencia.id')->all())->toBe([$a->id, $c->id, $b->id, $d->id])
        ->and($porUnidades->json('filas.1.unidades'))->toBe(3)
        ->and($porUnidades->json('filas.0.clase'))->toBeNull();

    $porValor = panel('mas-vendidos', ['orden' => 'valor'])->assertOk();
    expect(collect($porValor->json('filas'))->pluck('referencia.id')->all())->toBe([$b->id, $a->id, $c->id, $d->id])
        ->and(collect($porValor->json('filas'))->pluck('clase')->all())->toBe(['A', 'A', 'B', 'C'])
        ->and($porValor->json('filas.0.valor'))->toBe('1190000.00')
        ->and($porValor->json('total_valor'))->toBe('1975400.00');

    $porMargen = panel('mas-vendidos', ['orden' => 'margen'])->assertOk();
    expect(collect($porMargen->json('filas'))->pluck('referencia.id')->all())->toBe([$b->id, $a->id, $c->id, $d->id])
        ->and($porMargen->json('filas.0.margen'))->toBe('800000.00')
        ->and($porMargen->json('filas.0.margen_porcentaje'))->toBe(80)
        ->and($porMargen->json('filas.2.margen'))->toBe('15000.00');
});

it('S-45 (3): sin movimiento usa 120 días por defecto, la antigüedad de la nunca vendida, filtros, paginación, universal que rota y el parámetro dias', function () {
    $universal = CodigoUniversal::factory()->create();
    $r1 = refPanel(['codigo_universal_id' => $universal->id, 'categoria_id' => $universal->categoria_id], 3);
    $r6 = refPanel(['codigo_universal_id' => $universal->id, 'categoria_id' => $universal->categoria_id], 3);
    $r2 = refPanel([], 3);
    $r3 = refPanel([], 1);
    $r4 = refPanel([], 2);
    $r5 = refPanel([], 2);
    venderPanel([[$r1, 1]], ['cerrada_at' => now()->subDays(130)]);   // estancada (130 > 120)
    venderPanel([[$r2, 1]], ['cerrada_at' => now()->subDays(100)]);   // no (100 < 120)
    venderPanel([[$r3, 1]], ['cerrada_at' => now()->subDays(300)]);   // existencia 0: nunca estancada
    venderPanel([[$r6, 1]], ['cerrada_at' => now()->subDays(5)]);     // su universal rota
    DB::table('unidades')->where('referencia_id', $r4->id)->update(['created_at' => now()->subDays(200)]); // nunca vendida, 200 días
    DB::table('unidades')->where('referencia_id', $r5->id)->update(['created_at' => now()->subDays(10)]);  // nunca vendida, 10 días

    $r = panel('sin-movimiento')->assertOk();
    expect($r->json('dias'))->toBe(120)
        ->and(collect($r->json('data'))->pluck('referencia.id')->all())->toBe([$r4->id, $r1->id])
        ->and($r->json('data.0.ultima_venta'))->toBeNull()
        ->and($r->json('data.0.dias_sin_vender'))->toBe(200)
        ->and($r->json('data.0.universal_rota'))->toBeNull()
        ->and($r->json('data.1.dias_sin_vender'))->toBe(130)
        ->and($r->json('data.1.universal_rota'))->toBeTrue()
        ->and($r->json('totales'))->toMatchArray(['referencias' => 2, 'unidades' => 4, 'valor_costo' => '200000.00']);

    expect(collect(panel('sin-movimiento', ['categoria_id' => $r4->categoria_id])->json('data'))->pluck('referencia.id')->all())->toBe([$r4->id])
        ->and(collect(panel('sin-movimiento', ['proveedor_id' => $r1->proveedor_id])->json('data'))->pluck('referencia.id')->all())->toBe([$r1->id]);

    config(['panel.sin_movimiento.por_pagina' => 1]);
    $p2 = panel('sin-movimiento', ['page' => 2])->assertOk();
    expect($p2->json('total'))->toBe(2)->and($p2->json('data.0.referencia.id'))->toBe($r1->id);

    config(['panel.sin_movimiento.por_pagina' => 20]);
    expect(collect(panel('sin-movimiento', ['dias' => 60])->json('data'))->pluck('referencia.id')->all())->toContain($r2->id)
        ->and(panel('sin-movimiento', ['dias' => 60])->json('dias'))->toBe(60);
});

it('S-45 (4): el inventario vale a costo con TotalesIngreso y las unidades de alta cuentan como "sin costo"', function () {
    refPanel([], 3, 40000);                                           // 120.000
    refPanel([], 2, 10000, ['descuento_documento' => 2000]);          // base 18.000 (descuento del documento prorrateado)
    $alta = refPanel([]);
    actuarComo(Rol::Logistica);
    $this->postJson('/api/ajustes/entradas', ['referencia_id' => $alta->id, 'cantidad' => 2, 'motivo' => 'sobrante_inventario', 'metodo_captura' => 'escaneado'])->assertCreated();
    actuarComo(Rol::Admin);

    expect(panel('resumen')->assertOk()->json('inventario'))->toBe([
        'unidades' => 7,
        'valor_costo' => '138000.00',
        'valor_venta_sin_iva' => '700000.00',
        'unidades_sin_costo' => 2,
    ]);
});

it('S-45 (5): la antigüedad del stock reparte las unidades disponibles en sus rangos', function () {
    foreach ([10, 60, 120, 200, 400] as $dias) {
        $ref = refPanel([], 1);
        DB::table('unidades')->where('referencia_id', $ref->id)->update(['created_at' => now()->subDays($dias)]);
    }

    $rangos = collect(panel('antiguedad-stock')->assertOk()->json('rangos'))->keyBy('rango');
    expect($rangos->map(fn ($r) => $r['unidades'])->all())->toBe(['0-30' => 1, '31-90' => 1, '91-180' => 1, '181-365' => 1, 'mas-365' => 1])
        ->and($rangos['0-30']['valor_costo'])->toBe('50000.00')
        ->and($rangos['mas-365']['referencias'])->toBe(1);
});

it('S-45 (6): rotación da la cobertura en días por categoría y por proveedor, y "sin rotación" si no hubo ventas', function () {
    $rota = refPanel([], 10);
    $quieta = refPanel([], 5);
    venderPanel([[$rota, 3]]);

    $categorias = collect(panel('rotacion', ['agrupar' => 'categoria'])->assertOk()->json('filas'))->keyBy('id');
    expect($categorias[$rota->categoria_id])->toMatchArray(['unidades_vendidas' => 3, 'existencia' => 7, 'cobertura_dias' => 70, 'sobrestock' => false, 'margen' => '150000.00'])
        ->and($categorias[$quieta->categoria_id]['cobertura_dias'])->toBeNull()
        ->and($categorias[$quieta->categoria_id]['existencia'])->toBe(5);

    $proveedores = collect(panel('rotacion', ['agrupar' => 'proveedor'])->assertOk()->json('filas'))->keyBy('id');
    expect($proveedores[$rota->proveedor_id]['cobertura_dias'])->toBe(70)
        ->and($proveedores[$rota->proveedor_id]['compras'])->toBe('500000.00')
        ->and($proveedores[$quieta->proveedor_id]['cobertura_dias'])->toBeNull();
});

it('S-45 (7): reabastecer muestra la agotada que se vendía y la que quedó bajo mínimo, no la que casi no rota', function () {
    $agotada = refPanel([], 3);
    $bajo = refPanel(['stock_minimo' => 5], 8);
    $poca = refPanel([], 5);
    $sobrada = refPanel([], 100);
    venderPanel([[$agotada, 3], [$bajo, 3], [$poca, 1], [$sobrada, 3]]);

    $filas = collect(panel('reabastecer')->assertOk()->json('filas'))->keyBy('referencia.id');
    expect($filas->keys()->all())->toBe([$agotada->id, $bajo->id])
        ->and($filas[$agotada->id]['motivo'])->toBe('agotada')
        ->and($filas[$bajo->id])->toMatchArray(['motivo' => 'bajo_minimo', 'existencia' => 5, 'minimo_efectivo' => 5, 'cobertura_dias' => 50]);
});

it('S-45 (8): control da las bajas con su valor, los reversos, el % digitado y las ventas por usuario', function () {
    $ref = refPanel([], 8, 40000);
    $m1 = User::factory()->rol(Rol::Mostrador)->create();
    $m2 = User::factory()->rol(Rol::Mostrador)->create();

    actuarComo(Rol::Logistica);
    $codigo = Unidad::where('referencia_id', $ref->id)->orderByDesc('id')->value('codigo_barras');
    $this->postJson('/api/ajustes/salidas', ['motivo' => 'perdida', 'codigos' => [['codigo' => $codigo, 'metodo_captura' => 'escaneado']]])->assertCreated();
    actuarComo(Rol::Admin);

    venderPanel([[$ref, 2], [$ref, 1, null, MetodoCaptura::Digitado]], ['usuario' => $m1]);
    $b = venderPanel([[$ref, 2]], ['usuario' => $m2]);
    reversarItem($b, TipoReverso::Devolucion);
    $abierta = venderPanel([[$ref, 1]], ['usuario' => $m1, 'cerrar' => false]);
    reversarItem($abierta, TipoReverso::Anulacion);
    DB::table('ventas')->where('id', $abierta->id)->update(['created_at' => now()->subDays(2)]);

    $r = panel('control')->assertOk();
    expect($r->json('ajustes'))->toBe([['tipo' => 'baja', 'motivo' => 'perdida', 'unidades' => 1, 'valor_costo' => '40000.00', 'unidades_sin_costo' => 0]])
        ->and($r->json('reversos.devolucion'))->toBe(['reversos' => 1, 'items' => 1, 'valor' => '119000.00'])
        ->and($r->json('reversos.anulacion'))->toBe(['reversos' => 1, 'items' => 1, 'valor' => '119000.00'])
        ->and($r->json('capturas'))->toBe(['total' => 4, 'digitadas' => 1, 'porcentaje_digitado' => 25])
        ->and(collect($r->json('ventas_por_usuario'))->map(fn ($u) => [$u['usuario']['id'], $u['ventas'], $u['unidades'], $u['total']])->all())
        ->toBe([[$m1->id, 1, 3, '357000.00'], [$m2->id, 1, 1, '119000.00']])
        // S-46: la abierta vieja quedó sin ítems vigentes (se anuló su único ítem): no avisa.
        ->and($r->json('ventas_abiertas_viejas'))->toBe(0)
        ->and($r->json('dias_sin_ventas'))->toBe(29);
});

it('S-45 (9): margen = base − costo, con precio libre a crédito; sin costo se excluye y baja la cobertura; los reversados no cuentan', function () {
    $conCosto = refPanel([], 3, 50000);
    $sinCosto = refPanel([]);
    actuarComo(Rol::Logistica);
    $this->postJson('/api/ajustes/entradas', ['referencia_id' => $sinCosto->id, 'cantidad' => 1, 'motivo' => 'sobrante_inventario', 'metodo_captura' => 'escaneado'])->assertCreated();
    actuarComo(Rol::Admin);

    // Crédito: 119.000 (base 100.000), 107.100 libre (base 90.000), la tercera se devuelve; y una sin costo.
    $venta = venderPanel([[$conCosto, 1], [$conCosto, 1, 107100], [$conCosto, 1], [$sinCosto, 1]], ['credito' => true]);
    reversarItem($venta, TipoReverso::Devolucion, $venta->items()->orderBy('id')->skip(2)->value('id'));

    $r = panel('resumen')->assertOk();
    expect($r->json('ventas.total'))->toBe('345100.00')
        ->and($r->json('margen'))->toMatchArray([
            'margen' => '90000.00',
            'margen_porcentaje' => 47.4,
            'base_con_costo' => '190000.00',
            'costo' => '100000.00',
            'cobertura_costo' => 66.7,
            'unidades_sin_costo' => 1,
        ]);
});

it('S-45 (10): "días sin movimiento" vale 120, el admin lo cambia a 60 (auditado) y el informe lo usa; fuera de 30-730 es 422; otros roles 403', function () {
    $ref = refPanel([], 2);
    venderPanel([[$ref, 1]], ['cerrada_at' => now()->subDays(100)]);

    $this->getJson('/api/configuracion/dias-sin-movimiento')->assertOk()->assertJsonPath('valor', 120);
    expect(panel('sin-movimiento')->json('data'))->toBe([]);

    foreach ([29, 731, 45.5, 'x', null] as $malo) {
        $this->putJson('/api/configuracion/dias-sin-movimiento', ['valor' => $malo])->assertUnprocessable()->assertJsonValidationErrors('valor');
    }
    $admin = auth()->user();
    $this->putJson('/api/configuracion/dias-sin-movimiento', ['valor' => 60])->assertOk()->assertJsonPath('valor', 60);
    $this->getJson('/api/configuracion/dias-sin-movimiento')->assertJsonPath('valor', 60);

    $r = panel('sin-movimiento', refrescar: false)->assertOk();
    expect($r->json('dias'))->toBe(60)->and($r->json('data.0.referencia.id'))->toBe($ref->id);

    $audit = Auditoria::where('tabla', 'configuraciones')->where('campo', 'valor')->where('valor_nuevo', '60')->sole();
    expect($audit->valor_anterior)->toBe('120')->and($audit->usuario_id)->toBe($admin->id)->and($audit->created_at)->not->toBeNull();

    foreach ([Rol::Logistica, Rol::Mostrador] as $rol) {
        actuarComo($rol);
        $this->getJson('/api/configuracion/dias-sin-movimiento')->assertForbidden();
        $this->putJson('/api/configuracion/dias-sin-movimiento', ['valor' => 90])->assertForbidden();
    }
});

it('S-45 (11): mostrador y logística reciben 403 en todo el panel; el admin 200', function () {
    $informes = ['resumen', 'ventas-por-dia', 'mas-vendidos', 'reabastecer', 'sin-movimiento', 'antiguedad-stock', 'rotacion', 'control'];

    foreach ($informes as $informe) {
        panel($informe)->assertOk()->assertJsonStructure(['generado_at']);
    }
    foreach ([Rol::Mostrador, Rol::Logistica] as $rol) {
        actuarComo($rol);
        foreach ($informes as $informe) {
            panel($informe)->assertForbidden();
        }
    }
});

it('S-45 (12): la segunda llamada sale de la caché (refrescar=1 la salta) y cada informe hace un número acotado de consultas', function () {
    $informes = ['resumen', 'ventas-por-dia', 'mas-vendidos', 'reabastecer', 'sin-movimiento', 'antiguedad-stock', 'rotacion', 'control'];
    $contar = function (string $informe, array $params = [], bool $refrescar = true): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        panel($informe, $params, $refrescar)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $sembrar = function (int $n) {
        foreach (range(1, $n) as $_) {
            $ref = refPanel([], 5);
            venderPanel([[$ref, 3]]);
            venderPanel([[$ref, 1]], ['cerrada_at' => now()->subDays(200)]);
        }
    };

    // Caché: la primera consulta, la segunda no (solo lee el valor configurado); refrescar=1 vuelve a calcular.
    $sembrar(1);
    $primera = $contar('resumen', [], false);
    $generado = panel('resumen', [], false)->json('generado_at');
    $segunda = $contar('resumen', [], false);
    expect($primera)->toBeGreaterThan(5)
        ->and($segunda)->toBeLessThanOrEqual(1)
        ->and(panel('resumen', [], false)->json('generado_at'))->toBe($generado)
        ->and($contar('resumen'))->toBeGreaterThan(5);

    // Sin N+1: con 3 o con 6 referencias vendidas (y con stock), las mismas consultas.
    $sembrar(2);
    $antes = array_map(fn ($i) => $contar($i), $informes);
    $sembrar(3);
    $despues = array_map(fn ($i) => $contar($i), $informes);
    expect($despues)->toBe($antes);
});
