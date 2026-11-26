<?php

use App\Enums\Rol;
use App\Enums\TipoIngreso;
use App\Models\CodigoUniversal;
use App\Models\Ingreso;
use App\Models\IngresoItem;
use App\Models\Referencia;
use App\Models\User;
use App\Services\IngresoService;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => sembrarDemo());

/** Referencia nueva (sin compras) del proveedor dado o de uno nuevo. */
function refCostos(?int $proveedorId = null): Referencia
{
    return Referencia::factory()->create(array_filter(['proveedor_id' => $proveedorId, 'precio_venta' => 50000]));
}

/** Ingreso con líneas a medida; confirmado salvo que se pida lo contrario. */
function ingresoCostos(Referencia $ref, string $fecha, array $lineas, array $encabezado = [], bool $confirmar = true): Ingreso
{
    $usuario = User::factory()->rol(Rol::Logistica)->create();
    $servicio = app(IngresoService::class);
    $ingreso = $servicio->crearBorrador([
        'proveedor_id' => $ref->proveedor_id,
        'numero_documento' => 'C-'.random_int(100000, 999999),
        'fecha' => $fecha,
        ...$encabezado,
    ], $usuario);
    foreach ($lineas as $l) {
        $servicio->agregarItem($ingreso, ['referencia_id' => $ref->id, 'iva_porcentaje' => 0, 'descuento_porcentaje' => 0, ...$l]);
    }

    return $confirmar ? $servicio->confirmar($ingreso, $usuario) : $ingreso;
}

function historialCostos(array $params)
{
    return test()->getJson('/api/costos?'.http_build_query($params));
}

it('S-71: último y anterior por fecha del documento, aunque una compra más vieja se confirme después', function () {
    actuarComo(Rol::Logistica);
    $ref = refCostos();
    $a = ingresoCostos($ref, '2026-10-10', [['cantidad' => 1, 'costo_unitario' => 100000]]);
    $b = ingresoCostos($ref, '2026-11-01', [['cantidad' => 1, 'costo_unitario' => 120000]]);
    $c = ingresoCostos($ref, '2026-10-20', [['cantidad' => 1, 'costo_unitario' => 90000]]); // confirmada de última

    $r = historialCostos(['referencia_id' => $ref->id])->assertOk();

    expect($r->json('resumen.ultimo'))->toMatchArray(['valor' => '120000.00', 'fecha' => '2026-11-01', 'ingreso_id' => $b->id])
        ->and($r->json('resumen.anterior'))->toMatchArray(['valor' => '90000.00', 'fecha' => '2026-10-20', 'ingreso_id' => $c->id])
        ->and($r->json('resumen.variacion'))->toBe('33.3')
        ->and($r->json('resumen.compras'))->toBe(3)
        ->and(collect($r->json('data'))->pluck('ingreso_id')->all())->toBe([$b->id, $c->id, $a->id])
        ->and($r->json('data.0.ultimo'))->toBeTrue()
        ->and($r->json('data.1.anterior'))->toBeTrue()
        ->and($r->json('data.1.variacion'))->toBe('-10.0'); // 90.000 frente a 100.000

    // El listado de Inventario trae el mismo último costo.
    $fila = collect($this->getJson('/api/referencias?q='.$ref->codigo_referencia)->assertOk()->json('data'))->firstWhere('id', $ref->id);
    expect($fila['ultimo_costo'])->toMatchArray(['valor' => '120000.00', 'ingreso_id' => $b->id, 'tipo' => $b->tipo->value])
        ->not->toHaveKey('fecha'); // Inventario muestra solo el valor; la fecha está en el historial.
});

it('S-71: el costo neto aplica el descuento de línea y el del documento prorrateado, igual que el ingreso', function () {
    actuarComo(Rol::Logistica);
    $ref = refCostos();
    $otra = refCostos($ref->proveedor_id);
    $usuario = User::factory()->rol(Rol::Logistica)->create();
    $servicio = app(IngresoService::class);
    $ingreso = $servicio->crearBorrador(['proveedor_id' => $ref->proveedor_id, 'numero_documento' => 'DESC-1', 'fecha' => '2026-11-10'], $usuario);
    $linea = $servicio->agregarItem($ingreso, ['referencia_id' => $ref->id, 'cantidad' => 2, 'costo_unitario' => 1000, 'descuento_porcentaje' => 10, 'iva_porcentaje' => 19]);
    $servicio->agregarItem($ingreso, ['referencia_id' => $otra->id, 'cantidad' => 1, 'costo_unitario' => 3000, 'descuento_porcentaje' => 0, 'iva_porcentaje' => 19]);
    $servicio->actualizarEncabezado($ingreso, ['descuento_documento' => 480]);
    $servicio->confirmar($ingreso->fresh(), $usuario);

    // neto 1.800 + 3.000 = 4.800; descuento 480 → factor 0,9; base 1.620 ÷ 2 = 810.
    $base = $ingreso->fresh()->load('items')->totales()->lineas[$linea->id]['base'];
    $r = historialCostos(['referencia_id' => $ref->id])->assertOk();

    expect($base)->toBe('1620.00')
        ->and($r->json('data.0.costo_neto'))->toBe(bcdiv($base, '2', 2))
        ->and($r->json('data.0.costo_neto'))->toBe('810.00')
        ->and($r->json('data.0.costo_factura'))->toBe('1000.00')
        ->and($r->json('data.0.descuento_porcentaje'))->toBe('10.00')
        ->and($r->json('data.0.costo_con_iva'))->toBe('963.90');
});

it('S-71: los borradores no cuentan y una línea con costo 0 se lista pero no es el último', function () {
    actuarComo(Rol::Logistica);
    $ref = refCostos();
    $buena = ingresoCostos($ref, '2026-10-01', [['cantidad' => 1, 'costo_unitario' => 70000]]);
    ingresoCostos($ref, '2026-11-15', [['cantidad' => 1, 'costo_unitario' => 99000]], confirmar: false);
    $vieja = ingresoCostos($ref, '2026-11-05', [['cantidad' => 1, 'costo_unitario' => 80000]]);
    // Dato viejo "Sin costo": hoy no se puede confirmar así; se simula en la base.
    DB::table('ingreso_items')->where('ingreso_id', $vieja->id)->update(['costo_unitario' => 0]);

    $r = historialCostos(['referencia_id' => $ref->id])->assertOk();

    expect($r->json('resumen.ultimo.ingreso_id'))->toBe($buena->id)
        ->and($r->json('resumen.anterior'))->toBeNull()
        ->and($r->json('resumen.variacion'))->toBeNull()
        ->and($r->json('total'))->toBe(2) // el borrador no aparece
        ->and($r->json('data.0.ingreso_id'))->toBe($vieja->id)
        ->and($r->json('data.0.sin_costo'))->toBeTrue()
        ->and($r->json('data.0.costo_neto'))->toBeNull()
        ->and($r->json('data.0.ultimo'))->toBeFalse();
});

it('S-71 / RF-19: la remisión cubierta por una factura no cuenta doble; manda la factura', function () {
    $logistica = actuarComo(Rol::Logistica);
    $ref = refCostos();
    $vieja = ingresoCostos($ref, '2026-10-01', [['cantidad' => 2, 'costo_unitario' => 90000]]);
    $remision = ingresoCostos($ref, '2026-11-01', [['cantidad' => 4, 'costo_unitario' => 100000]], ['tipo' => TipoIngreso::Remision]);
    $servicio = app(IngresoService::class);
    $factura = $servicio->crearBorrador(['tipo' => TipoIngreso::Factura, 'proveedor_id' => $ref->proveedor_id, 'numero_documento' => 'FAC-9', 'fecha' => '2026-11-05'], $logistica);
    $servicio->agregarItem($factura, ['referencia_id' => $ref->id, 'cantidad' => 4, 'costo_unitario' => 110000, 'iva_porcentaje' => 0]);
    $servicio->ligarRemisiones($factura, [$remision->id]);
    $servicio->confirmar($factura->fresh(), $logistica);

    $r = historialCostos(['referencia_id' => $ref->id])->assertOk();
    $filas = collect($r->json('data'))->keyBy('ingreso_id');

    expect($r->json('resumen.ultimo.ingreso_id'))->toBe($factura->id)
        ->and($r->json('resumen.anterior.ingreso_id'))->toBe($vieja->id) // no la remisión
        ->and($r->json('resumen.compras'))->toBe(2)
        ->and($r->json('total'))->toBe(3) // se listan las tres filas
        ->and($filas[$remision->id]['cubierta_por'])->toBe('FAC-9')
        ->and($filas[$remision->id]['variacion'])->toBeNull()
        ->and($filas[$factura->id]['cubre_remisiones'])->toBe([$remision->numero_documento])
        ->and($filas[$factura->id]['variacion'])->toBe('22.2'); // 110.000 frente a 90.000
});

it('S-71 / RF-28: un ingreso corregido usa los valores vigentes y queda marcado', function () {
    actuarComo(Rol::Logistica);
    $ref = refCostos();
    $ingreso = ingresoCostos($ref, '2026-11-02', [['cantidad' => 1, 'costo_unitario' => 100000]]);
    $item = IngresoItem::where('ingreso_id', $ingreso->id)->first();
    $this->postJson("/api/ingresos/{$ingreso->id}/correcciones", [
        'numero_documento' => $ingreso->numero_documento,
        'fecha' => '2026-11-02',
        'forma_pago' => 'contado',
        'descuento_documento' => 0,
        'items' => [['id' => $item->id, 'cantidad' => 1, 'costo_unitario' => 150000, 'descuento_porcentaje' => 0, 'iva_porcentaje' => 0]],
        'motivo' => 'Costo mal digitado',
    ])->assertSuccessful();

    $r = historialCostos(['referencia_id' => $ref->id])->assertOk();

    expect($r->json('resumen.ultimo.valor'))->toBe('150000.00')
        ->and($r->json('data.0.corregida'))->toBeTrue();
});

it('S-71: un universal junta las compras de todas sus referencias con proveedor y calidad', function () {
    actuarComo(Rol::Logistica);
    $universal = CodigoUniversal::where('codigo', 'IM204')->firstOrFail();
    $refs = Referencia::where('codigo_universal_id', $universal->id)->with('proveedor')->orderBy('id')->take(2)->get();
    // La demo ya trae compras de hoy: con la misma fecha, desempata el id del ingreso (las nuevas van después).
    $hoy = now()->toDateString();
    $a = ingresoCostos($refs[0], $hoy, [['cantidad' => 1, 'costo_unitario' => 50000]]);
    $b = ingresoCostos($refs[1], $hoy, [['cantidad' => 1, 'costo_unitario' => 55000]]);

    $r = historialCostos(['codigo_universal_id' => $universal->id])->assertOk();
    $nuevas = collect($r->json('data'))->whereIn('ingreso_id', [$a->id, $b->id])->values();

    expect($r->json('producto.tipo'))->toBe('universal')
        ->and(count($r->json('producto.universal.referencias')))->toBe(Referencia::where('codigo_universal_id', $universal->id)->count())
        ->and($nuevas->pluck('referencia.id')->all())->toBe([$refs[1]->id, $refs[0]->id])
        ->and($nuevas[0]['proveedor']['nombre'])->toBe($refs[1]->proveedor->nombre)
        ->and($nuevas[0])->toHaveKey('referencia.calidad')
        ->and($r->json('resumen.ultimo.valor'))->toBe('55000.00')
        ->and($r->json('resumen.variacion'))->toBe('10.0');
});

it('S-71: permisos — mostrador ve solo el valor y no el historial; contable nada', function () {
    actuarComo(Rol::Logistica);
    $ref = refCostos();
    ingresoCostos($ref, '2026-11-01', [['cantidad' => 1, 'costo_unitario' => 70000]]);
    $buscar = '/api/referencias?q='.$ref->codigo_referencia;

    foreach ([Rol::Admin, Rol::Logistica] as $rol) {
        actuarComo($rol);
        $fila = collect($this->getJson($buscar)->assertOk()->json('data'))->firstWhere('id', $ref->id);
        expect(array_keys($fila['ultimo_costo']))->toBe(['valor', 'ingreso_id', 'tipo', 'numero_documento']);
        historialCostos(['referencia_id' => $ref->id])->assertOk();
    }

    actuarComo(Rol::Mostrador);
    $fila = collect($this->getJson($buscar)->assertOk()->json('data'))->firstWhere('id', $ref->id);
    expect($fila['ultimo_costo'])->toBe(['valor' => '70000.00']);
    historialCostos(['referencia_id' => $ref->id])->assertForbidden();

    actuarComo(Rol::Contable);
    $this->getJson($buscar)->assertForbidden();
    historialCostos(['referencia_id' => $ref->id])->assertForbidden();
});

it('S-71: el escaneo de venta, las ventas y la ficha de una referencia no llevan costos', function () {
    actuarComo(Rol::Logistica);
    ingresoCostos(referencia('RN-4410'), '2026-11-01', [['cantidad' => 1, 'costo_unitario' => 70000]]);
    actuarComo(Rol::Mostrador);
    $ventaId = abrirVenta();
    $escaneo = $this->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => unidadDe('RN-4410')->codigo_barras, 'metodo_captura' => 'escaneado'])->assertCreated();
    $venta = $this->getJson("/api/ventas/{$ventaId}")->assertOk();
    $ficha = $this->getJson('/api/referencias/'.referencia('RN-4410')->id)->assertOk();
    $buscar = $this->getJson('/api/buscar?q=RN-4410')->assertOk();

    foreach ([$escaneo, $venta, $ficha, $buscar] as $respuesta) {
        expect($respuesta->getContent())->not->toContain('ultimo_costo')->not->toContain('costo_neto');
    }
});
