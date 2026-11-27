<?php

use App\Enums\Rol;
use App\Models\CodigoUniversal;
use App\Models\Ingreso;
use App\Models\MovimientoInventario;
use App\Models\Referencia;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * S-87 (RF-21, S-44): GET /api/movimientos-inventario, consulta del historial de movimientos de inventario
 * (entradas por compra, ventas, reversos, bajas, reintegros y altas), solo lectura, admin y logística.
 * Orden created_at DESC, id DESC, paginado en servidor, sin N+1.
 */

const MOV_URL = '/api/movimientos-inventario';

/** Referencia con $unidades ingresadas por una compra confirmada (factura FAC-123 por defecto). */
function movCompra(string $codigo, int $unidades, array $encabezado = [], array $referencia = []): array
{
    $ref = Referencia::factory()->create(['codigo_referencia' => $codigo, 'precio_venta' => 80000, ...$referencia]);
    $ingreso = compra($ref, $unidades, 1000, ['tipo' => 'factura', 'numero_documento' => 'FAC-123', ...$encabezado]);

    return [$ref->refresh(), $ingreso];
}

function movCodigos(Referencia $ref): array
{
    return DB::table('unidades')->where('referencia_id', $ref->id)->orderBy('id')->pluck('codigo_barras')->all();
}

/** Baja (y opcionalmente reintegro) de una unidad como logística; deja la sesión anterior. */
function movAjustar(string $codigo, bool $reintegrar = false, string $metodo = 'escaneado'): void
{
    $anterior = auth()->user();
    actuarComo(Rol::Logistica);
    $lote = [['codigo' => $codigo, 'metodo_captura' => $metodo]];
    test()->postJson('/api/ajustes/salidas', ['motivo' => 'perdida', 'codigos' => $lote])->assertCreated();
    if ($reintegrar) {
        test()->postJson('/api/ajustes/reintegros', ['motivo' => 'error_de_registro', 'codigos' => $lote])->assertCreated();
    }
    if ($anterior) {
        test()->actingAs($anterior);
    }
}

/** Fija el momento (hora de Bogotá) de los movimientos indicados. */
function movFechar(array $ids, string $bogota): void
{
    DB::table('movimientos_inventario')->whereIn('id', $ids)->update(['created_at' => Carbon::parse($bogota, 'America/Bogota')->utc()]);
}

/** GET del historial con la query dada; devuelve la colección `data`. */
function movLista(?string $query = null)
{
    return collect(test()->getJson(MOV_URL.($query ? "?{$query}" : ''))->assertOk()->json('data'));
}

it('S-87 (RF-21): lista entradas por compra, ventas, bajas y reintegros de una referencia, con la entrada por «Factura …»', function () {
    [$ref] = movCompra('MOV-A', 3);
    [$ventaId] = venderComoMostrador('MOV-A');
    $codigos = movCodigos($ref);
    movAjustar($codigos[1], reintegrar: true);
    actuarComo(Rol::Logistica);

    $datos = movLista("referencia_id={$ref->id}&por_pagina=100");

    expect($datos->pluck('tipo')->unique()->sort()->values()->all())->toBe(['baja', 'ingreso', 'reintegro', 'venta'])
        ->and($datos->where('tipo', 'ingreso')->count())->toBe(3)
        ->and($datos->count())->toBe(6);

    $entrada = $datos->firstWhere('tipo', 'ingreso');
    expect($entrada['origen']['tipo'])->toBe('ingreso')
        ->and($entrada['origen']['etiqueta'])->toBe('Factura FAC-123')
        ->and($entrada['referencia']['codigo_referencia'])->toBe('MOV-A')
        ->and($entrada['referencia']['proveedor']['id'])->toBe($ref->proveedor_id)
        ->and($entrada['unidad']['codigo_barras'])->toBeIn($codigos)
        ->and($entrada['metodo_captura'])->toBeNull();

    $venta = $datos->firstWhere('tipo', 'venta');
    expect($venta['origen']['tipo'])->toBe('venta')
        ->and($venta['origen']['id'])->toBe($ventaId)
        ->and($venta['origen']['etiqueta'])->toBe("Venta #{$ventaId}")
        ->and($venta['usuario']['name'])->not->toBeEmpty()
        ->and($venta['metodo_captura'])->toBe('escaneado');

    foreach (['baja', 'reintegro'] as $tipo) {
        $m = $datos->firstWhere('tipo', $tipo);
        expect($m['origen']['tipo'])->toBe('ajuste')
            ->and($m['origen']['etiqueta'])->toStartWith('Ajuste #')
            ->and($m['unidad']['codigo_barras'])->toBe($codigos[1]);
    }

    // Forma exacta de cada elemento del contrato.
    expect(array_keys($entrada))->toEqualCanonicalizing(['id', 'tipo', 'created_at', 'metodo_captura', 'usuario', 'referencia', 'unidad', 'origen'])
        ->and(array_keys($entrada['referencia']))->toEqualCanonicalizing(['id', 'codigo_referencia', 'descripcion', 'calidad', 'proveedor'])
        ->and(array_keys($entrada['origen']))->toEqualCanonicalizing(['tipo', 'id', 'etiqueta']);
});

it('S-87 (RF-05): la entrada de una remisión se etiqueta «Remisión REM-1»', function () {
    [$ref] = movCompra('MOV-R', 1, ['tipo' => 'remision', 'numero_documento' => 'REM-1']);
    actuarComo(Rol::Admin);

    expect(movLista("referencia_id={$ref->id}")->sole()['origen']['etiqueta'])->toBe('Remisión REM-1');
});

it('S-87 (RF-21): la alta por ajuste de inventario aparece con origen «Ajuste #N»', function () {
    $ref = Referencia::factory()->create(['codigo_referencia' => 'MOV-ALTA', 'precio_venta' => 80000]);
    actuarComo(Rol::Logistica);
    $this->postJson('/api/ajustes/entradas', ['referencia_id' => $ref->id, 'cantidad' => 2, 'motivo' => 'sobrante_inventario', 'metodo_captura' => 'digitado'])->assertCreated();

    $datos = movLista("referencia_id={$ref->id}&tipo=alta");
    expect($datos)->toHaveCount(2)
        ->and($datos->first()['origen']['tipo'])->toBe('ajuste')
        ->and($datos->first()['origen']['etiqueta'])->toStartWith('Ajuste #')
        ->and($datos->first()['metodo_captura'])->toBe('digitado');
});

it('S-87 (RF-07): el reverso de una venta aparece con tipo reverso', function () {
    [$ref] = movCompra('MOV-REV', 2);
    [, $codigo] = venderComoMostrador('MOV-REV');
    actuarComo(Rol::Logistica);
    $this->postJson('/api/devoluciones', ['codigo' => $codigo, 'motivo' => 'Cliente se arrepintió', 'metodo_captura' => 'escaneado'])->assertSuccessful();

    $reverso = movLista("referencia_id={$ref->id}&tipo=reverso")->sole();
    expect($reverso['origen']['tipo'])->toBe('reverso')
        ->and($reverso['unidad']['codigo_barras'])->toBe($codigo);
});

it('S-87 (RF-28): incluye las correcciones de ingresos como «Corrección de ingreso #N»', function () {
    [$ref, $ingreso] = movCompra('MOV-COR', 3);
    actuarComo(Rol::Logistica);
    $item = $ingreso->items()->sole();
    $payload = ['items' => [[
        'id' => $item->id, 'cantidad' => 5, 'costo_unitario' => (float) $item->costo_unitario,
        'descuento_porcentaje' => (float) $item->descuento_porcentaje, 'iva_porcentaje' => (float) $item->iva_porcentaje,
    ]], 'motivo' => 'Error al digitar la factura'];
    $correccionId = $this->postJson("/api/ingresos/{$ingreso->id}/correcciones", $payload)->assertCreated()->json('correccion.id');

    $datos = movLista("referencia_id={$ref->id}&tipo=ingreso&por_pagina=100");
    $corregidas = $datos->filter(fn ($m) => ($m['origen']['tipo'] ?? null) === 'correccion_ingreso');
    expect($datos)->toHaveCount(5)
        ->and($corregidas)->toHaveCount(2)
        ->and($corregidas->every(fn ($m) => $m['origen']['id'] === $correccionId && $m['origen']['etiqueta'] === "Corrección de ingreso #{$correccionId}"))->toBeTrue();

    // Bajar la cantidad deja movimientos baja con el mismo tipo de origen.
    $payload['items'][0]['cantidad'] = 4;
    $c2 = $this->postJson("/api/ingresos/{$ingreso->id}/correcciones", $payload)->assertCreated()->json('correccion.id');
    $bajas = movLista("referencia_id={$ref->id}&tipo=baja");
    expect($bajas)->toHaveCount(1)
        ->and($bajas->first()['origen']['etiqueta'])->toBe("Corrección de ingreso #{$c2}");
});

it('S-87: los borradores no generan movimientos', function () {
    $ref = Referencia::factory()->create(['codigo_referencia' => 'MOV-BORR', 'precio_venta' => 80000]);
    $borrador = compra($ref, 2, 1000, ['tipo' => 'factura', 'numero_documento' => 'FAC-B1'], confirmar: false);
    expect(Ingreso::find($borrador->id)->estado->value)->toBe('borrador');
    actuarComo(Rol::Admin);

    expect(movLista("referencia_id={$ref->id}"))->toHaveCount(0);

    // Control: al confirmar sí aparecen.
    [$ref2] = movCompra('MOV-CONF', 2);
    expect(movLista("referencia_id={$ref2->id}"))->toHaveCount(2);
});

it('S-87: filtra por tipo, fechas (días de Bogotá), proveedor, usuario y método de captura', function () {
    [$a] = movCompra('MOV-F1', 2);
    [$b] = movCompra('MOV-F2', 2);
    venderComoMostrador('MOV-F1');
    $mostrador = User::where('rol', Rol::Mostrador)->latest('id')->first();
    movAjustar(movCodigos($b)[0], metodo: 'digitado');
    actuarComo(Rol::Admin);

    // tipo
    expect(movLista("tipo=venta&referencia_id={$a->id}"))->toHaveCount(1)
        ->and(movLista("tipo=baja&referencia_id={$b->id}"))->toHaveCount(1)
        ->and(movLista("tipo=ingreso&referencia_id={$a->id}"))->toHaveCount(2);

    // proveedor
    $deA = movLista("proveedor_id={$a->proveedor_id}");
    expect($deA->pluck('referencia.proveedor.id')->unique()->all())->toBe([$a->proveedor_id])
        ->and($deA)->toHaveCount(3); // 2 ingresos + 1 venta

    // usuario
    $deMostrador = movLista("usuario_id={$mostrador->id}");
    expect($deMostrador)->toHaveCount(1)
        ->and($deMostrador->first()['tipo'])->toBe('venta')
        ->and($deMostrador->first()['usuario']['id'])->toBe($mostrador->id);

    // método de captura
    $digitados = movLista('metodo_captura=digitado');
    expect($digitados)->toHaveCount(1)->and($digitados->first()['tipo'])->toBe('baja');
    expect(movLista("metodo_captura=escaneado&referencia_id={$a->id}")->pluck('tipo')->all())->toBe(['venta']);

    // fechas: 23:30 de Bogotá del día 10 ya es el día 11 en UTC y debe contar en el día 10.
    $ids = MovimientoInventario::where('referencia_id', $a->id)->orderBy('id')->pluck('id');
    movFechar([$ids[0]], '2026-03-10 23:30:00');
    movFechar([$ids[1]], '2026-03-11 00:10:00');
    movFechar([$ids[2]], '2026-03-12 08:00:00');
    $q = "referencia_id={$a->id}";
    expect(movLista("{$q}&desde=2026-03-10&hasta=2026-03-10")->pluck('id')->all())->toBe([$ids[0]])
        ->and(movLista("{$q}&desde=2026-03-11&hasta=2026-03-11")->pluck('id')->all())->toBe([$ids[1]])
        ->and(movLista("{$q}&desde=2026-03-11")->pluck('id')->all())->toBe([$ids[2], $ids[1]])
        ->and(movLista("{$q}&hasta=2026-03-10")->pluck('id')->all())->toBe([$ids[0]])
        ->and(movLista("{$q}&desde=2026-03-10&hasta=2026-03-12"))->toHaveCount(3);

    // filtros combinados
    expect(movLista("{$q}&tipo=venta&desde=2026-03-12&hasta=2026-03-12")->pluck('id')->all())->toBe(
        MovimientoInventario::whereIn('id', [$ids[2]])->where('tipo', 'venta')->pluck('id')->all()
    );
});

it('S-87: filtra por referencia, por universal y por q (código de referencia o fabricante, contiene)', function () {
    $universal = CodigoUniversal::factory()->create(['codigo' => 'IM777']);
    $dato = ['codigo_universal_id' => $universal->id, 'categoria_id' => $universal->categoria_id];
    [$u1] = movCompra('MOV-U1', 2, referencia: $dato);
    [$u2] = movCompra('MOV-U2', 1, referencia: $dato);
    [$suelta] = movCompra('XYZ-SUELTA', 1, referencia: ['codigo_fabricante' => 'FAB-98765']);
    actuarComo(Rol::Logistica);

    expect(movLista("referencia_id={$u1->id}"))->toHaveCount(2)
        ->and(movLista("referencia_id={$u1->id}")->pluck('referencia.id')->unique()->all())->toBe([$u1->id]);

    $porUniversal = movLista("universal_id={$universal->id}");
    expect($porUniversal)->toHaveCount(3)
        ->and($porUniversal->pluck('referencia.id')->unique()->sort()->values()->all())->toBe(collect([$u1->id, $u2->id])->sort()->values()->all());

    // q: contiene, en código de referencia o de fabricante.
    expect(movLista('q=MOV-U')->pluck('referencia.id')->unique()->sort()->values()->all())->toBe(collect([$u1->id, $u2->id])->sort()->values()->all())
        ->and(movLista('q=SUELTA'))->toHaveCount(1)
        ->and(movLista('q=B-987')->pluck('referencia.id')->unique()->all())->toBe([$suelta->id])
        ->and(movLista('q=NO-EXISTE'))->toHaveCount(0);
});

it('S-87: pagina en servidor y no hace más consultas con 5 que con 60 movimientos (sin N+1)', function () {
    movCompra('MOV-N1', 5);
    actuarComo(Rol::Logistica);

    $consultas = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->getJson(MOV_URL.'?por_pagina=100')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $con5 = $consultas();
    expect(movLista('por_pagina=100'))->toHaveCount(5);

    // Más de 60 movimientos con varias referencias, proveedores, ventas, ajustes y usuarios distintos.
    foreach (range(1, 6) as $i) {
        [$r] = movCompra("MOV-N{$i}B", 8);
        venderComoMostrador("MOV-N{$i}B");
        movAjustar(movCodigos($r)[1]);
    }
    actuarComo(Rol::Logistica);
    $total = MovimientoInventario::count();
    expect($total)->toBeGreaterThanOrEqual(60);

    expect($consultas())->toBe($con5);

    // Paginación real en servidor.
    $p = $this->getJson(MOV_URL.'?por_pagina=10')->assertOk();
    expect($p->json('data'))->toHaveCount(10)
        ->and($p->json('meta.total'))->toBe($total)
        ->and($p->json('meta.per_page'))->toBe(10);
    $p2 = $this->getJson(MOV_URL.'?por_pagina=10&page=2')->assertOk();
    expect(array_intersect(array_column($p->json('data'), 'id'), array_column($p2->json('data'), 'id')))->toBeEmpty();
});

it('S-87 (S-44): mostrador y contable reciben 403', function () {
    movCompra('MOV-403', 1);

    foreach ([Rol::Mostrador, Rol::Contable] as $rol) {
        actuarComo($rol);
        $this->getJson(MOV_URL)->assertForbidden()->assertJsonPath('message', 'Su rol no tiene acceso a este módulo.');
    }
});

it('S-87 (S-44): admin y logística consultan', function () {
    [$ref] = movCompra('MOV-OK', 2);

    foreach ([Rol::Admin, Rol::Logistica] as $rol) {
        actuarComo($rol);
        $this->getJson(MOV_URL."?referencia_id={$ref->id}")->assertOk()->assertJsonCount(2, 'data');
    }
});

it('S-87: el historial es de solo lectura (sin rutas POST, PUT, PATCH ni DELETE)', function () {
    [$ref] = movCompra('MOV-RO', 1);
    $id = MovimientoInventario::where('referencia_id', $ref->id)->value('id');
    actuarComo(Rol::Admin);

    foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $metodo) {
        foreach ([MOV_URL, MOV_URL."/{$id}"] as $url) {
            expect($this->{$metodo}($url, [])->getStatusCode())->toBeIn([404, 405]);
        }
    }
    expect(MovimientoInventario::count())->toBe(1);
});

it('S-87: valida los filtros (hasta < desde, tipo, q, método, fechas, por_pagina)', function () {
    actuarComo(Rol::Admin);

    $this->getJson(MOV_URL.'?desde=2026-03-10&hasta=2026-03-09')->assertUnprocessable()->assertJsonValidationErrors('hasta');
    $this->getJson(MOV_URL.'?tipo=inventado')->assertUnprocessable()->assertJsonValidationErrors('tipo');
    $this->getJson(MOV_URL.'?q=a')->assertUnprocessable()->assertJsonValidationErrors('q');
    $this->getJson(MOV_URL.'?q='.str_repeat('a', 61))->assertUnprocessable()->assertJsonValidationErrors('q');
    $this->getJson(MOV_URL.'?metodo_captura=telepatia')->assertUnprocessable()->assertJsonValidationErrors('metodo_captura');
    $this->getJson(MOV_URL.'?desde=10-03-2026')->assertUnprocessable()->assertJsonValidationErrors('desde');
    $this->getJson(MOV_URL.'?por_pagina=101')->assertUnprocessable()->assertJsonValidationErrors('por_pagina');
    $this->getJson(MOV_URL.'?por_pagina=0')->assertUnprocessable()->assertJsonValidationErrors('por_pagina');
});

it('S-87: ordena por fecha descendente (y por id descendente a igual fecha)', function () {
    [$ref] = movCompra('MOV-ORD', 4);
    $ids = MovimientoInventario::where('referencia_id', $ref->id)->orderBy('id')->pluck('id');
    movFechar([$ids[0]], '2026-03-12 09:00:00');
    movFechar([$ids[1]], '2026-03-10 09:00:00');
    movFechar([$ids[2]], '2026-03-12 09:00:00'); // empata con $ids[0]: gana el id mayor
    movFechar([$ids[3]], '2026-03-11 09:00:00');
    actuarComo(Rol::Admin);

    expect(movLista("referencia_id={$ref->id}")->pluck('id')->all())->toBe([$ids[2], $ids[0], $ids[3], $ids[1]]);
});
