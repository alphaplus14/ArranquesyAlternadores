<?php

use App\Enums\EstadoUnidad;
use App\Enums\Rol;
use App\Enums\TipoIngreso;
use App\Enums\TipoMovimiento;
use App\Exceptions\RegistroInmutableException;
use App\Models\Auditoria;
use App\Models\Ingreso;
use App\Models\IngresoCorreccion;
use App\Models\MovimientoInventario;
use App\Models\Referencia;
use App\Models\Unidad;
use App\Models\User;
use App\Services\IngresoService;
use Illuminate\Support\Facades\Route;

/*
 * RF-28 (S-58, S-60, S-61): corrección completa de un ingreso confirmado (factura o remisión), con
 * vista previa sin escritura, efecto en inventario, auditoría y lista de correcciones.
 */

beforeEach(function () {
    $this->logistica = actuarComo(Rol::Logistica);
});

function corRef(?int $proveedorId = null): Referencia
{
    return Referencia::factory()->create(array_filter(['proveedor_id' => $proveedorId, 'precio_venta' => 50000]));
}

/** Líneas actuales del ingreso como payload de `items`, con cambios por id. */
function corItems(Ingreso $ingreso, array $cambios = []): array
{
    return $ingreso->items()->orderBy('id')->get()->map(fn ($i) => [
        'id' => $i->id,
        'cantidad' => $i->cantidad,
        'costo_unitario' => (float) $i->costo_unitario,
        'descuento_porcentaje' => (float) $i->descuento_porcentaje,
        'iva_porcentaje' => (float) $i->iva_porcentaje,
        ...($cambios[$i->id] ?? []),
    ])->all();
}

function corCorregir(Ingreso $ingreso, array $datos, ?string $motivo = 'Error al digitar la factura')
{
    return test()->postJson("/api/ingresos/{$ingreso->id}/correcciones", [...$datos, ...($motivo === null ? [] : ['motivo' => $motivo])]);
}

function corVistaPrevia(Ingreso $ingreso, array $datos)
{
    return test()->postJson("/api/ingresos/{$ingreso->id}/correcciones/vista-previa", $datos);
}

/** Regla técnica: la existencia guardada coincide con las unidades disponibles. */
function corCuadra(Referencia $ref): void
{
    expect($ref->fresh()->existencia)->toBe(Unidad::where('referencia_id', $ref->id)->where('estado', EstadoUnidad::Disponible)->count());
}

function corUnidades(Ingreso $ingreso, ?EstadoUnidad $estado = null)
{
    return Unidad::whereIn('ingreso_item_id', $ingreso->items()->select('id'))->when($estado, fn ($q) => $q->where('estado', $estado))->orderBy('id');
}

it('RF-28: subir la cantidad crea unidades con códigos nuevos, movimiento ingreso y existencia; quedan pendientes de etiqueta', function () {
    $ref = corRef();
    $ingreso = compra($ref, 3, 1000);
    $codigosAntes = corUnidades($ingreso)->pluck('codigo_barras')->all();
    $item = $ingreso->items()->sole();

    $r = corCorregir($ingreso, ['items' => corItems($ingreso, [$item->id => ['cantidad' => 5]])])->assertCreated()
        ->assertJsonPath('correccion.unidades_creadas', 2)
        ->assertJsonPath('correccion.unidades_retiradas', 0)
        ->assertJsonPath('correccion.total_anterior', '3000.00')
        ->assertJsonPath('correccion.total_nuevo', '5000.00')
        ->assertJsonPath('ingreso.total', '5000.00')
        ->assertJsonPath('ingreso.corregido', true);

    $nuevas = corUnidades($ingreso)->whereNotIn('codigo_barras', $codigosAntes)->get();
    expect($nuevas)->toHaveCount(2)
        ->and($nuevas->every(fn ($u) => $u->estado === EstadoUnidad::Disponible && $u->etiqueta_impresa_at === null && $u->ingreso_item_id === $item->id))->toBeTrue()
        ->and($nuevas->pluck('codigo_barras')->unique())->toHaveCount(2);
    $movs = MovimientoInventario::whereIn('unidad_id', $nuevas->pluck('id'))->get();
    expect($movs->pluck('tipo')->unique()->all())->toBe([TipoMovimiento::Ingreso])
        ->and($movs->pluck('origen_type')->unique()->all())->toBe(['correccion_ingreso'])
        ->and($movs->first()->origen_id)->toBe($r->json('correccion.id'));
    expect($ref->fresh()->existencia)->toBe(5);
    corCuadra($ref);

    // Pendientes de etiqueta en la pantalla de Etiquetas.
    $unidades = $this->getJson("/api/ingresos/{$ingreso->id}/unidades")->assertOk()->json();
    expect(collect($unidades)->where('etiqueta_impresa', false)->count())->toBe(5);
});

it('RF-28 (S-60): bajar la cantidad retira primero las unidades sin etiqueta impresa, con movimiento baja', function () {
    $ref = corRef();
    $ingreso = compra($ref, 4, 1000);
    $unidades = corUnidades($ingreso)->get();
    Unidad::whereKey($unidades->take(2)->pluck('id'))->update(['etiqueta_impresa_at' => now()]);
    $item = $ingreso->items()->sole();

    corCorregir($ingreso, ['items' => corItems($ingreso, [$item->id => ['cantidad' => 2]])])->assertCreated()
        ->assertJsonPath('correccion.unidades_retiradas', 2);

    $retiradas = corUnidades($ingreso, EstadoUnidad::Retirada)->get();
    expect($retiradas->pluck('id')->all())->toBe($unidades->slice(2)->pluck('id')->values()->all())
        ->and($retiradas->every(fn ($u) => $u->etiqueta_impresa_at === null))->toBeTrue();
    expect(MovimientoInventario::whereIn('unidad_id', $retiradas->pluck('id'))->where('tipo', TipoMovimiento::Baja)->where('origen_type', 'correccion_ingreso')->count())->toBe(2);
    expect(Unidad::count())->toBe(4); // no se borra ninguna
    expect($ref->fresh()->existencia)->toBe(2);
    corCuadra($ref);
});

it('RF-28 (S-60): retirar unidades con etiqueta impresa se advierte y exige confirmar_etiquetas_impresas', function () {
    $ref = corRef();
    $ingreso = compra($ref, 3, 1000);
    corUnidades($ingreso)->update(['etiqueta_impresa_at' => now()]);
    $item = $ingreso->items()->sole();
    $datos = ['items' => corItems($ingreso, [$item->id => ['cantidad' => 1]])];

    corVistaPrevia($ingreso, $datos)->assertOk()
        ->assertJsonPath('con_etiqueta_impresa', 2)
        ->assertJsonPath('requiere_confirmar_etiquetas', true)
        ->assertJsonPath('advertencias.0.tipo', 'con_etiqueta_impresa');
    corCorregir($ingreso, $datos)->assertUnprocessable()->assertJsonPath('motivo', 'etiquetas_impresas');
    corCorregir($ingreso, [...$datos, 'confirmar_etiquetas_impresas' => true])->assertCreated();
    expect($ref->fresh()->existencia)->toBe(1);
    corCuadra($ref);
});

it('RF-28: no se baja de lo vendido y no se quita una línea con unidades vendidas; ventas intactas', function () {
    $ref = corRef();
    $ingreso = compra($ref, 3, 1000);
    [$ventaId] = venderComoMostrador($ref->codigo_referencia);
    venderComoMostrador($ref->codigo_referencia);
    $item = $ingreso->items()->sole();
    $venta = DB::table('ventas')->where('id', $ventaId)->first();

    $vista = corVistaPrevia($ingreso, ['items' => corItems($ingreso, [$item->id => ['cantidad' => 1]])])->assertOk();
    expect($vista->json('errores.0.tipo'))->toBe('vendidas')->and($vista->json('errores.0.vendidas'))->toBe(2);
    corCorregir($ingreso, ['items' => corItems($ingreso, [$item->id => ['cantidad' => 1]])])->assertUnprocessable();

    // Quitar la línea (no enviarla) con unidades vendidas: rechazado. Se agrega otra para que no quede vacío.
    $otra = corRef($ref->proveedor_id);
    corCorregir($ingreso, ['items' => [['referencia_id' => $otra->id, 'cantidad' => 1, 'costo_unitario' => 500]]])->assertUnprocessable();

    // Bajar a 2 sí: queda 1 disponible que se retira.
    corCorregir($ingreso, ['items' => corItems($ingreso, [$item->id => ['cantidad' => 2]])])->assertCreated();
    expect(corUnidades($ingreso, EstadoUnidad::Vendida)->count())->toBe(2)
        ->and((array) DB::table('ventas')->where('id', $ventaId)->first())->toEqual((array) $venta);
    corCuadra($ref);
});

it('RF-28: agregar una línea (misma referencia del proveedor) y quitar otra sin vender', function () {
    $ref = corRef();
    $otra = corRef($ref->proveedor_id);
    $ingreso = compra($ref, 2, 1000);
    $item = $ingreso->items()->sole();

    corCorregir($ingreso, ['items' => [['referencia_id' => $otra->id, 'cantidad' => 3, 'costo_unitario' => 2000, 'iva_porcentaje' => 0]]])->assertCreated()
        ->assertJsonPath('correccion.unidades_creadas', 3)
        ->assertJsonPath('correccion.unidades_retiradas', 2)
        ->assertJsonPath('ingreso.total', '6000.00');

    expect($item->fresh()->cantidad)->toBe(0); // la línea quitada queda en 0: sus unidades la siguen citando
    expect($ref->fresh()->existencia)->toBe(0)->and($otra->fresh()->existencia)->toBe(3);
    corCuadra($ref);
    corCuadra($otra);
    $this->assertDatabaseHas('auditoria', ['tabla' => 'ingreso_items', 'campo' => '*']);
});

it('RF-28 (S-58): una línea nueva debe ser de una referencia activa del mismo proveedor; la referencia de una línea no cambia', function () {
    $ref = corRef();
    $ingreso = compra($ref, 1, 1000);
    $item = $ingreso->items()->sole();
    $ajena = corRef();
    $inactiva = corRef($ref->proveedor_id);
    $inactiva->forceFill(['activo' => false])->save();
    $base = corItems($ingreso);

    corCorregir($ingreso, ['items' => [...$base, ['referencia_id' => $ajena->id, 'cantidad' => 1, 'costo_unitario' => 10]]])->assertUnprocessable();
    corCorregir($ingreso, ['items' => [...$base, ['referencia_id' => $inactiva->id, 'cantidad' => 1, 'costo_unitario' => 10]]])->assertUnprocessable();
    corCorregir($ingreso, ['items' => corItems($ingreso, [$item->id => ['referencia_id' => $ajena->id]])])->assertUnprocessable();
});

it('RF-28: cambiar costo, descuento, IVA, forma de pago y vencimiento recalcula total y deuda', function () {
    $ref = corRef();
    $ingreso = compra($ref, 10, 1000); // contado, 10.000
    $item = $ingreso->items()->sole();

    corCorregir($ingreso, [
        'forma_pago' => 'credito',
        'vence_el' => '2026-12-31',
        'descuento_documento' => 1000,
        'items' => corItems($ingreso, [$item->id => ['costo_unitario' => 2000, 'descuento_porcentaje' => 10, 'iva_porcentaje' => 19]]),
    ])->assertCreated()
        // 10 × 2.000 = 20.000; −10 % = 18.000; −1.000 = 17.000; IVA 19 % = 3.230 → 20.230
        ->assertJsonPath('ingreso.total', '20230.00')
        ->assertJsonPath('ingreso.forma_pago', 'credito')
        ->assertJsonPath('ingreso.vence_el', '2026-12-31')
        ->assertJsonPath('correccion.unidades_creadas', 0);

    actuarComo(Rol::Admin);
    expect($this->getJson("/api/proveedores/{$ref->proveedor_id}/saldo")->assertOk()->json('saldo'))->toBe('20230.00');
    corCuadra($ref);

    // A crédito sin vencimiento: rechazado.
    actuarComo(Rol::Logistica);
    corCorregir($ingreso->fresh(), ['vence_el' => null])->assertUnprocessable();
});

it('RF-28 (S-61): una remisión cubierta por una factura solo admite corregir número y fecha', function () {
    $ref = corRef();
    $remision = ingresar($ref, 4);
    $servicio = app(IngresoService::class);
    $factura = $servicio->crearBorrador(['tipo' => TipoIngreso::Factura, 'proveedor_id' => $ref->proveedor_id, 'numero_documento' => 'FEV-1', 'fecha' => now()->toDateString()], $this->logistica);
    $servicio->agregarItem($factura, ['referencia_id' => $ref->id, 'cantidad' => 4, 'costo_unitario' => 1000]);
    $servicio->ligarRemisiones($factura, [$remision->id]);
    $servicio->confirmar($factura, $this->logistica);
    $remision->refresh();
    $item = $remision->items()->sole();

    corCorregir($remision, ['items' => corItems($remision, [$item->id => ['cantidad' => 5]])])->assertUnprocessable()->assertJsonPath('motivo', 'remision_cubierta');
    corCorregir($remision, ['forma_pago' => 'credito', 'vence_el' => '2026-12-31'])->assertUnprocessable()->assertJsonPath('motivo', 'remision_cubierta');
    corCorregir($remision, ['numero_documento' => 'REM-CORREGIDA', 'fecha' => now()->subDay()->toDateString()])->assertCreated();
    expect($remision->fresh()->numero_documento)->toBe('REM-CORREGIDA');
});

it('RF-28 (S-61): en una factura con remisiones se recalcula cantidad_cubierta y se exige aceptar la diferencia', function () {
    $ref = corRef();
    $remision = ingresar($ref, 4);
    $servicio = app(IngresoService::class);
    $factura = $servicio->crearBorrador(['tipo' => TipoIngreso::Factura, 'proveedor_id' => $ref->proveedor_id, 'numero_documento' => 'FEV-2', 'fecha' => now()->toDateString()], $this->logistica);
    $servicio->agregarItem($factura, ['referencia_id' => $ref->id, 'cantidad' => 4, 'costo_unitario' => 1000]);
    $servicio->ligarRemisiones($factura, [$remision->id]);
    $factura = $servicio->confirmar($factura, $this->logistica);
    $item = $factura->items()->sole();
    expect($ref->fresh()->existencia)->toBe(4);

    // Sube a 6: cubre 4 y crea 2 unidades nuevas.
    corCorregir($factura, ['items' => corItems($factura, [$item->id => ['cantidad' => 6]])])->assertCreated()->assertJsonPath('correccion.unidades_creadas', 2);
    expect($item->fresh()->cantidad_cubierta)->toBe(4)->and($ref->fresh()->existencia)->toBe(6);

    // Baja a 3: cubre 3 de lo recibido (4), retira las 2 nuevas y exige aceptar la diferencia.
    $datos = ['items' => corItems($factura->fresh(), [$item->id => ['cantidad' => 3]])];
    corVistaPrevia($factura, $datos)->assertOk()->assertJsonPath('requiere_aceptar_diferencia', true)->assertJsonPath('unidades_retirar', 2);
    corCorregir($factura, $datos)->assertUnprocessable()->assertJsonPath('motivo', 'diferencia_remision');
    corCorregir($factura, [...$datos, 'aceptar_diferencia' => true, 'diferencia_motivo' => 'El proveedor facturó 3'])->assertCreated()->assertJsonPath('correccion.unidades_retiradas', 2);

    expect($item->fresh()->cantidad_cubierta)->toBe(3)
        ->and($factura->fresh()->diferencia_aceptada)->toBeTrue()
        ->and($factura->fresh()->diferencia_motivo)->toBe('El proveedor facturó 3')
        ->and($ref->fresh()->existencia)->toBe(4);
    corCuadra($ref);
});

it('RF-28: la vista previa no escribe nada', function () {
    $ref = corRef();
    $ingreso = compra($ref, 2, 1000);
    $item = $ingreso->items()->sole();
    $antes = [Unidad::count(), MovimientoInventario::count(), Auditoria::count(), IngresoCorreccion::count(), $ref->fresh()->existencia, $ingreso->fresh()->total];

    corVistaPrevia($ingreso, ['numero_documento' => 'OTRO', 'items' => corItems($ingreso, [$item->id => ['cantidad' => 5, 'costo_unitario' => 3000]])])->assertOk()
        ->assertJsonPath('total_anterior', '2000.00')
        ->assertJsonPath('total_nuevo', '15000.00')
        ->assertJsonPath('unidades_crear', 3)
        ->assertJsonPath('por_referencia.0.crear', 3)
        ->assertJsonPath('errores', []);

    expect([Unidad::count(), MovimientoInventario::count(), Auditoria::count(), IngresoCorreccion::count(), $ref->fresh()->existencia, $ingreso->fresh()->total])->toEqual($antes)
        ->and($ingreso->fresh()->numero_documento)->not->toBe('OTRO');
});

it('RF-28 (S-58): proveedor, tipo e id no se corrigen; motivo obligatorio; sin cambios y número duplicado rechazados', function () {
    $ref = corRef();
    $ingreso = compra($ref, 1, 1000);
    $otro = compra($ref, 1, 1000);

    corCorregir($ingreso, ['proveedor_id' => corRef()->proveedor_id])->assertUnprocessable()->assertJsonValidationErrors('proveedor_id');
    corCorregir($ingreso, ['tipo' => 'factura'])->assertUnprocessable()->assertJsonValidationErrors('tipo');
    corCorregir($ingreso, ['id' => 99])->assertUnprocessable()->assertJsonValidationErrors('id');
    corCorregir($ingreso, ['numero_documento' => 'X-1'], null)->assertUnprocessable()->assertJsonValidationErrors('motivo');
    corCorregir($ingreso, ['numero_documento' => 'X-1'], 'abc')->assertUnprocessable()->assertJsonValidationErrors('motivo');
    corCorregir($ingreso, ['items' => corItems($ingreso)])->assertUnprocessable()->assertJsonPath('message', 'No hay cambios.');
    corCorregir($ingreso, ['numero_documento' => $otro->numero_documento])->assertUnprocessable();

    // El servicio también rechaza los campos no corregibles (llamado directo).
    expect(fn () => app(IngresoService::class)->corregir($ingreso, ['proveedor_id' => 1], 'Motivo largo', $this->logistica))
        ->toThrow(\App\Exceptions\ReglaInventarioException::class);
    expect(IngresoCorreccion::count())->toBe(0);
});

it('RF-28 / RF-11: la corrección queda en ingreso_correcciones (inmutable) y en la auditoría general', function () {
    $ref = corRef();
    $ingreso = compra($ref, 2, 1000);
    $item = $ingreso->items()->sole();

    corCorregir($ingreso, ['numero_documento' => 'DOC-OK', 'items' => corItems($ingreso, [$item->id => ['costo_unitario' => 1500]])], 'Costo mal digitado')->assertCreated();

    $c = IngresoCorreccion::sole();
    expect($c->motivo)->toBe('Costo mal digitado')
        ->and($c->usuario_id)->toBe($this->logistica->id)
        ->and($c->antes['lineas'][0]['costo_unitario'])->toBe('1000.00')
        ->and($c->despues['lineas'][0]['costo_unitario'])->toBe('1500.00')
        ->and($c->despues['encabezado']['numero_documento'])->toBe('DOC-OK');
    $this->assertDatabaseHas('auditoria', ['tabla' => 'ingresos', 'registro_id' => $ingreso->id, 'campo' => 'numero_documento', 'valor_nuevo' => 'DOC-OK', 'usuario_id' => $this->logistica->id]);
    $this->assertDatabaseHas('auditoria', ['tabla' => 'ingresos', 'registro_id' => $ingreso->id, 'campo' => 'total', 'valor_nuevo' => '3000.00']);
    $this->assertDatabaseHas('auditoria', ['tabla' => 'ingreso_items', 'registro_id' => $item->id, 'campo' => 'costo_unitario', 'valor_anterior' => '1000.00', 'valor_nuevo' => '1500.00']);

    expect(fn () => $c->update(['motivo' => 'x']))->toThrow(RegistroInmutableException::class)
        ->and(fn () => $c->delete())->toThrow(RegistroInmutableException::class);
});

it('RF-28: ninguna otra ruta edita un ingreso confirmado', function () {
    $ref = corRef();
    $ingreso = compra($ref, 2, 1000);
    $item = $ingreso->items()->sole();

    $this->putJson("/api/ingresos/{$ingreso->id}", ['numero_documento' => 'NUEVO'])->assertStatus(409);
    $this->patchJson("/api/ingresos/{$ingreso->id}/items/{$item->id}", ['cantidad' => 9])->assertStatus(409);
    $this->deleteJson("/api/ingresos/{$ingreso->id}/items/{$item->id}")->assertStatus(409);
    $this->postJson("/api/ingresos/{$ingreso->id}/items", ['referencia_id' => $ref->id, 'cantidad' => 1, 'costo_unitario' => 1])->assertStatus(409);
    expect(fn () => $ingreso->fresh()->update(['numero_documento' => 'X']))->toThrow(RegistroInmutableException::class);
    expect($ingreso->fresh()->numero_documento)->not->toBe('NUEVO')->and($item->fresh()->cantidad)->toBe(2);
});

it('RF-28: GET /ingresos marca los corregidos y filtra con corregidos=1', function () {
    $ref = corRef();
    $corregido = compra($ref, 1, 1000);
    $normal = compra($ref, 1, 1000);
    corCorregir($corregido, ['numero_documento' => 'DOC-COR'])->assertCreated();

    $todos = collect($this->getJson('/api/ingresos')->assertOk()->json('data'))->keyBy('id');
    expect($todos[$corregido->id]['corregido'])->toBeTrue()
        ->and($todos[$corregido->id]['correcciones_count'])->toBe(1)
        ->and($todos[$normal->id]['corregido'])->toBeFalse();
    expect(collect($this->getJson('/api/ingresos?corregidos=1')->json('data'))->pluck('id')->all())->toBe([$corregido->id]);
    expect($this->getJson("/api/ingresos/{$corregido->id}")->assertOk()->json('correcciones.0.motivo'))->toBe('Error al digitar la factura');
});

it('RF-28: el admin ve la lista de correcciones con filtros; logística y mostrador 403', function () {
    $ref = corRef();
    $a = compra($ref, 1, 1000);
    $b = compra(corRef(), 1, 1000);
    corCorregir($a, ['numero_documento' => 'A-1'])->assertCreated();
    corCorregir($b, ['numero_documento' => 'B-1'])->assertCreated();

    $this->getJson('/api/ingresos/correcciones')->assertForbidden();
    actuarComo(Rol::Mostrador);
    $this->getJson('/api/ingresos/correcciones')->assertForbidden();
    $this->postJson("/api/ingresos/{$a->id}/correcciones", ['numero_documento' => 'Z', 'motivo' => 'Prueba mostrador'])->assertForbidden();

    actuarComo(Rol::Admin);
    $r = $this->getJson('/api/ingresos/correcciones')->assertOk();
    expect($r->json('data'))->toHaveCount(2)
        ->and($r->json('data.0.ingreso.numero_documento'))->toBe('B-1')
        ->and($r->json('data.0.antes.encabezado'))->toHaveKey('numero_documento');
    expect(collect($this->getJson("/api/ingresos/correcciones?proveedor_id={$ref->proveedor_id}")->json('data'))->pluck('ingreso.id')->all())->toBe([$a->id]);
    expect($this->getJson("/api/ingresos/correcciones?usuario_id={$this->logistica->id}")->json('data'))->toHaveCount(2);
    expect($this->getJson('/api/ingresos/correcciones?desde=2000-01-01&hasta=2000-01-02')->json('data'))->toHaveCount(0);
});

it('RF-28: el admin también corrige (opera Ingresos como logística)', function () {
    $ingreso = compra(corRef(), 1, 1000);
    actuarComo(Rol::Admin);
    corCorregir($ingreso, ['numero_documento' => 'ADM-1'])->assertCreated();
});

it('RF-28: no hay rutas DELETE nuevas para correcciones', function () {
    $conDelete = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_contains($r->uri(), 'correcciones') && array_intersect(['DELETE', 'PUT', 'PATCH'], $r->methods()));

    expect($conDelete)->toBeEmpty();
});
