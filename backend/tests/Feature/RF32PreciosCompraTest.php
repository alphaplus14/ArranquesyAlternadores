<?php

use App\Enums\Rol;
use App\Enums\TipoIngreso;
use App\Models\Categoria;
use App\Models\CodigoUniversal;
use App\Models\Proveedor;
use App\Models\Referencia;
use App\Models\User;
use App\Services\IngresoService;

/*
 * RF-32 / S-70: historial de precios de compra, en pantalla. Cuenta la factura confirmada y la
 * remisión que ninguna factura confirmada cubre. El último y el anterior salen al identificar
 * un producto, con su proveedor. Una corrección (RF-28) actualiza el costo; no crea otra compra.
 */

function preciosCompra(array $params = [])
{
    return test()->getJson('/api/reportes/precios-compra?'.http_build_query($params));
}

function refPrecio(string $codigo, ?Proveedor $proveedor = null, array $extra = []): Referencia
{
    return Referencia::factory()->create([
        'codigo_referencia' => $codigo,
        'proveedor_id' => ($proveedor ?? Proveedor::factory()->create())->id,
        'descripcion' => $extra['descripcion'] ?? 'Repuesto de prueba',
        ...$extra,
    ]);
}

it('RF-32: el último precio y el anterior traen fecha, documento y proveedor', function () {
    $ref = refPrecio('PC-100');
    compra($ref, 1, 1000, ['fecha' => '2026-01-10', 'numero_documento' => 'ANT-100']);
    compra($ref, 1, 1500, ['fecha' => '2026-06-10', 'numero_documento' => 'ULT-150']);
    actuarComo(Rol::Logistica);

    $respuesta = preciosCompra(['q' => 'PC-100'])->assertOk();

    expect($respuesta->json('resumen.ultimo.costo_unitario'))->toBe('1500.00')
        ->and($respuesta->json('resumen.ultimo.numero_documento'))->toBe('ULT-150')
        ->and($respuesta->json('resumen.ultimo.proveedor.id'))->toBe($ref->proveedor_id)
        ->and($respuesta->json('resumen.anterior.costo_unitario'))->toBe('1000.00')
        ->and($respuesta->json('resumen.anterior.numero_documento'))->toBe('ANT-100')
        ->and($respuesta->json('resumen.variacion'))->toBe('500.00')
        ->and($respuesta->json('data.0.numero_documento'))->toBe('ULT-150')
        ->and($respuesta->json('data.1.numero_documento'))->toBe('ANT-100');
});

it('RF-32: un universal muestra el último y el anterior aunque sean de proveedores distintos', function () {
    $cat = Categoria::factory()->conUniversal()->create();
    $universal = CodigoUniversal::factory()->create(['categoria_id' => $cat->id, 'codigo' => 'IM204']);
    $alfa = Proveedor::factory()->create(['nombre' => 'Alfa']);
    $beta = Proveedor::factory()->create(['nombre' => 'Beta']);
    $vieja = refPrecio('ALFA-1', $alfa, ['categoria_id' => $cat->id, 'codigo_universal_id' => $universal->id]);
    $nueva = refPrecio('BETA-1', $beta, ['categoria_id' => $cat->id, 'codigo_universal_id' => $universal->id]);
    compra($vieja, 1, 1000, ['fecha' => '2026-01-01', 'numero_documento' => 'A-1']);
    compra($nueva, 1, 1800, ['fecha' => '2026-06-01', 'numero_documento' => 'B-1']);
    actuarComo(Rol::Logistica);

    $respuesta = preciosCompra(['q' => 'IM204'])->assertOk();

    expect($respuesta->json('resumen.ultimo.numero_documento'))->toBe('B-1')
        ->and($respuesta->json('resumen.ultimo.proveedor.nombre'))->toBe('Beta')
        ->and($respuesta->json('resumen.ultimo.referencia.codigo_universal.codigo'))->toBe('IM204')
        ->and($respuesta->json('resumen.anterior.numero_documento'))->toBe('A-1')
        ->and($respuesta->json('resumen.anterior.proveedor.nombre'))->toBe('Alfa')
        ->and($respuesta->json('resumen.variacion'))->toBe('800.00');
});

it('RF-32: la remisión cubierta por una factura confirmada no sale; mientras la factura es borrador, sí', function () {
    $ref = refPrecio('PC-CUB');
    $remision = compra($ref, 2, 800, ['fecha' => '2026-03-01', 'numero_documento' => 'REM-800']);
    $factura = compra($ref, 2, 1200, ['tipo' => TipoIngreso::Factura, 'fecha' => '2026-03-15', 'numero_documento' => 'FAC-1200'], confirmar: false);
    $servicio = app(IngresoService::class);
    $servicio->ligarRemisiones($factura, [$remision->id]);
    actuarComo(Rol::Logistica);

    $enBorrador = preciosCompra(['referencia_id' => $ref->id])->assertOk();
    expect(collect($enBorrador->json('data'))->pluck('numero_documento')->all())->toBe(['REM-800']);

    $servicio->confirmar($factura->fresh(), User::findOrFail($factura->usuario_id));

    $cubierta = preciosCompra(['referencia_id' => $ref->id])->assertOk();
    expect(collect($cubierta->json('data'))->pluck('numero_documento')->all())->toBe(['FAC-1200'])
        ->and($cubierta->json('resumen.ultimo.costo_unitario'))->toBe('1200.00')
        ->and($cubierta->json('resumen.anterior'))->toBeNull();
});

it('RF-32: un borrador y una línea quitada (cantidad 0) no entran', function () {
    $proveedor = Proveedor::factory()->create();
    $ref = refPrecio('PC-FUERA', $proveedor);
    $otra = refPrecio('PC-QUEDA', $proveedor);
    compra($ref, 1, 500, ['numero_documento' => 'BORR-1'], confirmar: false);
    compra($ref, 1, 900, ['fecha' => '2026-04-01', 'numero_documento' => 'VIG-900']);
    $quitada = compra($ref, 1, 700, ['fecha' => '2026-02-01', 'numero_documento' => 'CERO-700'], confirmar: false);
    $servicio = app(IngresoService::class);
    $servicio->agregarItem($quitada, ['referencia_id' => $otra->id, 'cantidad' => 1, 'costo_unitario' => 400, 'iva_porcentaje' => 0]);
    $servicio->confirmar($quitada->fresh(), User::findOrFail($quitada->usuario_id));
    $conservada = $quitada->items()->where('referencia_id', $otra->id)->sole();
    actuarComo(Rol::Logistica);

    // Sacar la línea de la lista la deja en cantidad 0 (RF-28); no se envía cantidad 0.
    $this->postJson("/api/ingresos/{$quitada->id}/correcciones", [
        'motivo' => 'Línea que no venía en la factura',
        'items' => [[
            'id' => $conservada->id,
            'cantidad' => 1,
            'costo_unitario' => 400,
            'descuento_porcentaje' => 0,
            'iva_porcentaje' => 0,
        ]],
    ])->assertCreated();

    $respuesta = preciosCompra(['referencia_id' => $ref->id])->assertOk();

    expect(collect($respuesta->json('data'))->pluck('numero_documento')->all())->toBe(['VIG-900'])
        ->and($respuesta->json('resumen.ultimo.numero_documento'))->toBe('VIG-900');
});

it('RF-32: corregir el costo deja el valor vigente y lo marca, sin duplicar la compra', function () {
    $ref = refPrecio('PC-CORR');
    $ingreso = compra($ref, 1, 1000, ['fecha' => '2026-05-01', 'numero_documento' => 'CORR-1']);
    $item = $ingreso->items()->sole();
    actuarComo(Rol::Logistica);

    $this->postJson("/api/ingresos/{$ingreso->id}/correcciones", [
        'motivo' => 'Costo mal digitado',
        'items' => [[
            'id' => $item->id,
            'cantidad' => 1,
            'costo_unitario' => 1500,
            'descuento_porcentaje' => 0,
            'iva_porcentaje' => 0,
        ]],
    ])->assertCreated();

    $respuesta = preciosCompra(['referencia_id' => $ref->id])->assertOk();

    expect($respuesta->json('data'))->toHaveCount(1)
        ->and($respuesta->json('data.0.costo_unitario'))->toBe('1500.00')
        ->and($respuesta->json('data.0.corregido'))->toBeTrue()
        ->and($respuesta->json('resumen.anterior'))->toBeNull();
});

it('RF-32: editar el costo en el borrador no marca la compra como corregida', function () {
    $ref = refPrecio('PC-BORR');
    $ingreso = compra($ref, 1, 1000, ['numero_documento' => 'BORR-COSTO'], confirmar: false);
    $servicio = app(IngresoService::class);
    $servicio->actualizarItem($ingreso, $ingreso->items()->sole(), ['costo_unitario' => 1300]);
    $servicio->confirmar($ingreso->fresh(), User::findOrFail($ingreso->usuario_id));
    actuarComo(Rol::Admin);

    $respuesta = preciosCompra(['q' => 'PC-BORR'])->assertOk();

    expect($respuesta->json('data.0.costo_unitario'))->toBe('1300.00')
        ->and($respuesta->json('data.0.corregido'))->toBeFalse();
});

it('RF-32: el neto resta el descuento de la línea y no el del documento', function () {
    $ref = refPrecio('PC-NETO');
    $ingreso = compra($ref, 2, 2000, ['numero_documento' => 'NETO-1', 'descuento_documento' => 100], confirmar: false);
    $servicio = app(IngresoService::class);
    $servicio->actualizarItem($ingreso, $ingreso->items()->sole(), ['descuento_porcentaje' => 10]);
    $servicio->confirmar($ingreso->fresh(), User::findOrFail($ingreso->usuario_id));
    actuarComo(Rol::Logistica);

    $fila = preciosCompra(['q' => 'PC-NETO'])->assertOk()->json('data.0');

    expect($fila['costo_unitario'])->toBe('2000.00')
        ->and($fila['descuento_porcentaje'])->toBe('10.00')
        ->and($fila['costo_neto'])->toBe('1800.00');
});

it('RF-32: proveedor, categoría, fechas y descripción acotan', function () {
    $cat = Categoria::factory()->create(['nombre' => 'Sensores precio']);
    $otra = Categoria::factory()->create();
    $alfa = Proveedor::factory()->create(['nombre' => 'Alfa filtros']);
    $beta = Proveedor::factory()->create(['nombre' => 'Beta filtros']);
    $buscada = refPrecio('FIL-1', $alfa, ['categoria_id' => $cat->id, 'descripcion' => 'Sensor de temperatura']);
    $mismaCategoria = refPrecio('FIL-2', $beta, ['categoria_id' => $cat->id]);
    $otro = refPrecio('FIL-3', $alfa, ['categoria_id' => $otra->id]);
    compra($buscada, 1, 1000, ['fecha' => '2026-01-15', 'numero_documento' => 'ENE']);
    compra($buscada, 1, 1100, ['fecha' => '2026-08-15', 'numero_documento' => 'AGO']);
    compra($mismaCategoria, 1, 900, ['fecha' => '2026-08-15', 'numero_documento' => 'BETA']);
    compra($otro, 1, 800, ['fecha' => '2026-08-15', 'numero_documento' => 'OTRA']);
    actuarComo(Rol::Logistica);

    $numeros = fn (array $params) => collect(preciosCompra($params)->assertOk()->json('data'))->pluck('numero_documento')->sort()->values()->all();

    expect($numeros(['proveedor_id' => $alfa->id, 'categoria_id' => $cat->id]))->toBe(['AGO', 'ENE'])
        ->and($numeros(['q' => 'temperatura']))->toBe(['AGO', 'ENE'])
        ->and($numeros(['desde' => '2026-08-01', 'hasta' => '2026-08-31', 'categoria_id' => $cat->id]))->toBe(['AGO', 'BETA'])
        ->and(preciosCompra(['q' => 'temperatura'])->json('resumen.ultimo.numero_documento'))->toBe('AGO');

    preciosCompra(['desde' => '2026-09-01', 'hasta' => '2026-01-01'])->assertUnprocessable()->assertJsonValidationErrors('hasta');
    preciosCompra(['proveedor_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('proveedor_id');
});

it('RF-32: sin un producto identificado no arma el resumen, y admin entra', function () {
    $a = refPrecio('LIBRE-A', extra: ['descripcion' => 'Uno suelto']);
    $b = refPrecio('LIBRE-B', extra: ['descripcion' => 'Otro suelto']);
    compra($a, 1, 100, ['numero_documento' => 'LA']);
    compra($b, 1, 200, ['numero_documento' => 'LB']);
    actuarComo(Rol::Admin);

    $respuesta = preciosCompra()->assertOk();

    expect($respuesta->json('resumen'))->toBeNull()
        ->and(collect($respuesta->json('data'))->pluck('numero_documento')->sort()->values()->all())->toBe(['LA', 'LB']);
});

it('RF-32: mostrador y contable no consultan los precios de compra', function () {
    actuarComo(Rol::Mostrador);
    preciosCompra()->assertForbidden();

    actuarComo(Rol::Contable);
    preciosCompra()->assertForbidden();
});
