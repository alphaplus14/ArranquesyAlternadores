<?php

use App\Enums\Rol;
use App\Enums\TipoIngreso;
use App\Models\Auditoria;
use App\Models\Ingreso;
use App\Models\IngresoCorreccion;
use App\Models\Referencia;
use App\Services\IngresoService;
use Illuminate\Support\Facades\DB;

/*
 * S-95 (RF-28, S-86, S-66): la corrección de un ingreso confirmado no deja mezclados el descuento del
 * documento y el descuento por línea (un 0 no cuenta). Un documento anterior a S-86 que ya los mezclaba
 * se corrige si ningún descuento cambia. El descuento del documento llega en $ o en % (se guarda en $).
 * Supuesto pendiente de confirmar con el contador (pendiente 3).
 */

beforeEach(function () {
    $this->logistica = actuarComo(Rol::Logistica);
});

function s95Ref(): Referencia
{
    return Referencia::factory()->create(['precio_venta' => 50000]);
}

/** Factura de 10 × 1.000 sin IVA, con el descuento del documento dado (en $ o en %). */
function s95Factura(Referencia $ref, array $descuento = []): Ingreso
{
    return compra($ref, 10, 1000, ['tipo' => TipoIngreso::Factura, ...$descuento]);
}

/** Documento anterior a S-86: descuento del documento de 1.000 y 10 % en la línea (total 8.000). */
function s95Mixto(Referencia $ref): Ingreso
{
    $ingreso = s95Factura($ref);
    DB::table('ingreso_items')->where('ingreso_id', $ingreso->id)->update(['descuento_porcentaje' => 10]);
    DB::table('ingresos')->where('id', $ingreso->id)->update(['descuento_documento' => 1000, 'total' => 8000]);

    return $ingreso->fresh();
}

function s95Linea(Ingreso $ingreso, array $cambios): array
{
    $i = $ingreso->items()->sole();

    return [[
        'id' => $i->id,
        'cantidad' => $i->cantidad,
        'costo_unitario' => (float) $i->costo_unitario,
        'descuento_porcentaje' => (float) $i->descuento_porcentaje,
        'iva_porcentaje' => (float) $i->iva_porcentaje,
        ...$cambios,
    ]];
}

function s95Corregir(Ingreso $ingreso, array $datos)
{
    return test()->postJson("/api/ingresos/{$ingreso->id}/correcciones", [...$datos, 'motivo' => 'Corrección de descuentos']);
}

it('S-95: quitar el descuento del documento (en $) y dar descuento por línea corrige el total', function () {
    $ingreso = s95Factura(s95Ref(), ['descuento_documento' => 1000]);
    expect($ingreso->total)->toBe('9000.00');

    s95Corregir($ingreso, ['descuento_documento' => 0, 'items' => s95Linea($ingreso, ['descuento_porcentaje' => 20])])
        ->assertCreated()
        ->assertJsonPath('ingreso.total', '8000.00'); // 10.000 − 20 %
    expect($ingreso->fresh()->descuento_documento)->toBe('0.00');
});

it('S-95: un descuento del documento capturado en % se guarda en $ al confirmar y se puede cambiar por línea', function () {
    $ingreso = s95Factura(s95Ref(), ['descuento_documento_porcentaje' => 10]);
    expect($ingreso->descuento_documento)->toBe('1000.00')
        ->and($ingreso->descuento_documento_porcentaje)->toBeNull();

    s95Corregir($ingreso, ['descuento_documento' => 0, 'items' => s95Linea($ingreso, ['descuento_porcentaje' => 5])])
        ->assertCreated()
        ->assertJsonPath('ingreso.total', '9500.00');
});

it('S-95: en la corrección el descuento del documento también llega en % y se convierte a $ (S-66)', function () {
    $ingreso = s95Factura(s95Ref());

    s95Corregir($ingreso, ['descuento_documento_porcentaje' => 15])
        ->assertCreated()
        ->assertJsonPath('ingreso.total', '8500.00');
    expect($ingreso->fresh())->descuento_documento->toBe('1500.00')->descuento_documento_porcentaje->toBeNull();
});

it('S-95: dar descuento por línea sin quitar el del documento → 422 en descuento_porcentaje', function () {
    $ingreso = s95Factura(s95Ref(), ['descuento_documento' => 1000]);

    s95Corregir($ingreso, ['items' => s95Linea($ingreso, ['descuento_porcentaje' => 10])])
        ->assertUnprocessable()
        ->assertJsonPath('errors.descuento_porcentaje.0', 'El documento ya tiene descuento: quítelo para dar descuento por ítem.');
    expect($ingreso->fresh()->total)->toBe('9000.00');
});

it('S-95: dar descuento del documento con líneas que ya tienen descuento → 422 en descuento_documento', function () {
    $ref = s95Ref();
    $ingreso = s95Factura($ref);
    s95Corregir($ingreso, ['items' => s95Linea($ingreso, ['descuento_porcentaje' => 10])])->assertCreated();

    s95Corregir($ingreso->fresh(), ['descuento_documento' => 500])
        ->assertUnprocessable()
        ->assertJsonPath('errors.descuento_documento.0', 'Hay ítems con descuento: quítelos para usar un descuento del documento.');
    s95Corregir($ingreso->fresh(), ['descuento_documento_porcentaje' => 5])
        ->assertUnprocessable()
        ->assertJsonPath('errors.descuento_documento.0', 'Hay ítems con descuento: quítelos para usar un descuento del documento.');
});

it('S-95: un 0 no cuenta como descuento; pasar a "sin descuento" deja la base completa', function () {
    $ingreso = s95Factura(s95Ref(), ['descuento_documento' => 1000]);

    s95Corregir($ingreso, ['descuento_documento' => 0, 'items' => s95Linea($ingreso, ['descuento_porcentaje' => 0])])
        ->assertCreated()
        ->assertJsonPath('ingreso.total', '10000.00');
    expect(IngresoCorreccion::latest('id')->first())
        ->antes->encabezado->condicion_descuento->toBe('documento')
        ->despues->encabezado->condicion_descuento->toBe('ninguno');
});

it('S-95: un documento mixto anterior a S-86 se corrige si no cambia ningún descuento', function () {
    $ingreso = s95Mixto(s95Ref());

    // Solo el costo: 10 × 2.000 = 20.000; −10 % = 18.000; −1.000 = 17.000.
    s95Corregir($ingreso, ['items' => s95Linea($ingreso, ['costo_unitario' => 2000])])
        ->assertCreated()
        ->assertJsonPath('ingreso.total', '17000.00');
    expect(IngresoCorreccion::latest('id')->first()->despues['encabezado']['condicion_descuento'])->toBe('mixto');
});

it('S-95: en un documento mixto, cambiar un descuento exige quedar en una sola condición', function () {
    $ingreso = s95Mixto(s95Ref());

    s95Corregir($ingreso, ['items' => s95Linea($ingreso, ['descuento_porcentaje' => 5])])
        ->assertUnprocessable()
        ->assertJsonPath('errors.descuento_porcentaje.0', 'El documento ya tiene descuento: quítelo para dar descuento por ítem.');
    s95Corregir($ingreso, ['descuento_documento' => 2000])
        ->assertUnprocessable()
        ->assertJsonPath('errors.descuento_documento.0', 'Hay ítems con descuento: quítelos para usar un descuento del documento.');

    // Elegir "Descuento por producto": el del documento a 0.
    s95Corregir($ingreso, ['descuento_documento' => 0])
        ->assertCreated()
        ->assertJsonPath('ingreso.total', '9000.00');
});

it('S-95: la vista previa coincide con el resultado y muestra el cambio de condición', function () {
    $ingreso = s95Factura(s95Ref(), ['descuento_documento' => 1000]);
    $datos = ['descuento_documento' => 0, 'items' => s95Linea($ingreso, ['descuento_porcentaje' => 20])];

    $previa = $this->postJson("/api/ingresos/{$ingreso->id}/correcciones/vista-previa", $datos)->assertOk();
    expect($previa->json('totales.descuento_documento'))->toBe('0.00')
        ->and($previa->json('antes.encabezado.condicion_descuento'))->toBe('documento')
        ->and($previa->json('despues.encabezado.condicion_descuento'))->toBe('producto');

    $total = s95Corregir($ingreso, $datos)->assertCreated()->json('ingreso.total');
    expect($previa->json('total_nuevo'))->toBe($total);

    // La vista previa también rechaza la mezcla.
    $this->postJson("/api/ingresos/{$ingreso->id}/correcciones/vista-previa", ['descuento_documento' => 300])
        ->assertUnprocessable()
        ->assertJsonPath('errors.descuento_documento.0', 'Hay ítems con descuento: quítelos para usar un descuento del documento.');
});

it('S-95 (RF-11): la auditoría guarda el descuento del documento y el de la línea, antes y después', function () {
    $ingreso = s95Factura(s95Ref(), ['descuento_documento' => 1000]);
    $item = $ingreso->items()->sole();

    s95Corregir($ingreso, ['descuento_documento' => 0, 'items' => s95Linea($ingreso, ['descuento_porcentaje' => 20])])->assertCreated();

    $documento = Auditoria::where(['tabla' => 'ingresos', 'registro_id' => $ingreso->id, 'campo' => 'descuento_documento'])->latest('id')->first();
    $linea = Auditoria::where(['tabla' => 'ingreso_items', 'registro_id' => $item->id, 'campo' => 'descuento_porcentaje'])->latest('id')->first();
    expect((float) $documento->valor_anterior)->toBe(1000.0)
        ->and((float) $documento->valor_nuevo)->toBe(0.0)
        ->and((float) $linea->valor_anterior)->toBe(0.0)
        ->and((float) $linea->valor_nuevo)->toBe(20.0)
        ->and($linea->usuario_id)->toBe($this->logistica->id);
});

it('S-95 (S-61): una remisión cubierta por una factura no corrige descuentos, solo número y fecha', function () {
    $ref = s95Ref();
    $remision = ingresar($ref, 4);
    $servicio = app(IngresoService::class);
    $factura = $servicio->crearBorrador(['tipo' => TipoIngreso::Factura, 'proveedor_id' => $ref->proveedor_id, 'numero_documento' => 'FEV-S95', 'fecha' => now()->toDateString()], $this->logistica);
    $servicio->agregarItem($factura, ['referencia_id' => $ref->id, 'cantidad' => 4, 'costo_unitario' => 1000]);
    $servicio->ligarRemisiones($factura, [$remision->id]);
    $servicio->confirmar($factura, $this->logistica);
    $remision->refresh();

    s95Corregir($remision, ['descuento_documento' => 100])->assertUnprocessable()->assertJsonPath('motivo', 'remision_cubierta');
    s95Corregir($remision, ['items' => s95Linea($remision, ['descuento_porcentaje' => 5])])->assertUnprocessable()->assertJsonPath('motivo', 'remision_cubierta');
});

it('S-95: mostrador y contable no corrigen (403)', function (Rol $rol) {
    $ingreso = s95Factura(s95Ref(), ['descuento_documento' => 1000]);
    actuarComo($rol);

    s95Corregir($ingreso, ['descuento_documento' => 0])->assertForbidden();
    $this->postJson("/api/ingresos/{$ingreso->id}/correcciones/vista-previa", ['descuento_documento' => 0])->assertForbidden();
})->with([
    'mostrador' => [Rol::Mostrador],
    'contable' => [Rol::Contable],
]);
