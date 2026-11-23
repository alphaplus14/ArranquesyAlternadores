<?php

use App\Enums\Rol;
use App\Exceptions\RegistroInmutableException;
use App\Models\AjusteInventario;
use App\Models\AjusteItem;
use App\Models\MovimientoInventario;
use App\Models\Unidad;
use App\Models\Venta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

beforeEach(fn () => sembrarDemo());

/** @param list<string> $codigos */
function loteAjuste(array $codigos, string $metodo = 'escaneado'): array
{
    return array_map(fn ($c) => ['codigo' => $c, 'metodo_captura' => $metodo], $codigos);
}

function retirarPorApi(array $codigos, string $motivo = 'garantia', ?string $nota = null)
{
    return test()->postJson('/api/ajustes/salidas', ['motivo' => $motivo, 'nota' => $nota, 'codigos' => loteAjuste($codigos)]);
}

it('RF-21 (a): retirar una unidad disponible la deja retirada, baja la existencia y registra el movimiento baja', function () {
    $logistica = actuarComo(Rol::Logistica);
    $unidad = unidadDe('RN-4410');

    $respuesta = $this->postJson('/api/ajustes/salidas', [
        'motivo' => 'garantia',
        'codigos' => [['codigo' => $unidad->codigo_barras, 'metodo_captura' => 'digitado']],
    ])->assertCreated()
        ->assertJsonPath('tipo', 'baja')
        ->assertJsonPath('motivo', 'garantia')
        ->assertJsonPath('usuario.id', $logistica->id)
        ->assertJsonPath('cantidad', 1)
        ->assertJsonPath('unidades.0.codigo_barras', $unidad->codigo_barras)
        ->assertJsonPath('unidades.0.estado', 'retirada');

    expect($unidad->refresh()->estado->value)->toBe('retirada')
        ->and(referencia('RN-4410')->existencia)->toBe(1);

    $ajusteId = $respuesta->json('id');
    $this->assertDatabaseHas('movimientos_inventario', [
        'tipo' => 'baja',
        'unidad_id' => $unidad->id,
        'usuario_id' => $logistica->id,
        'metodo_captura' => 'digitado',
        'origen_type' => 'ajuste',
        'origen_id' => $ajusteId,
    ]);
    $this->assertDatabaseHas('ajuste_items', ['ajuste_id' => $ajusteId, 'unidad_id' => $unidad->id, 'metodo_captura' => 'digitado']);
    expect(MovimientoInventario::where('origen_type', 'ajuste')->first()->origen)->toBeInstanceOf(AjusteInventario::class);
});

it('RF-21 / criterio 1: tres unidades por garantía bajan la existencia de cada referencia y quedan en el historial', function () {
    $logistica = actuarComo(Rol::Logistica);
    $codigos = [unidadDe('RN-4410')->codigo_barras, unidadDe('DE-IM204-A')->codigo_barras, unidadDe('AC-77812')->codigo_barras];

    retirarPorApi($codigos)->assertCreated()->assertJsonPath('cantidad', 3);

    expect(Unidad::whereIn('codigo_barras', $codigos)->where('estado', 'retirada')->count())->toBe(3)
        ->and(referencia('RN-4410')->existencia)->toBe(1)
        ->and(referencia('DE-IM204-A')->existencia)->toBe(1)
        ->and(referencia('AC-77812')->existencia)->toBe(1);

    $this->getJson('/api/ajustes')->assertOk()
        ->assertJsonPath('data.0.motivo', 'garantia')
        ->assertJsonPath('data.0.usuario.name', $logistica->name)
        ->assertJsonPath('data.0.cantidad', 3)
        ->assertJsonCount(3, 'data.0.unidades')
        ->assertJsonStructure(['data' => [['created_at', 'unidades' => [['referencia' => ['codigo_referencia', 'proveedor' => ['nombre']]]]]]]);

    $this->artisan('inventario:recalcular')->assertSuccessful();
});

it('RF-21 (b): retirar una unidad vendida o ya retirada responde 422 y el lote es atómico', function () {
    actuarComo(Rol::Logistica);
    [, $vendida] = venderComoMostrador('RN-4410');
    $libre = unidadDe('AC-77812')->codigo_barras;

    retirarPorApi([$libre, $vendida])->assertUnprocessable()
        ->assertJsonPath('codigo', $vendida)
        ->assertJsonPath('message', "La unidad {$vendida} está vendida; no se puede retirar.");

    // Nada entró: ni la unidad libre, ni el ajuste, ni movimientos.
    expect(Unidad::where('codigo_barras', $libre)->first()->estado->value)->toBe('disponible')
        ->and(referencia('AC-77812')->existencia)->toBe(2)
        ->and(AjusteInventario::count())->toBe(0)
        ->and(MovimientoInventario::where('tipo', 'baja')->count())->toBe(0);

    retirarPorApi([$libre])->assertCreated();
    retirarPorApi([$libre])->assertUnprocessable()
        ->assertJsonPath('message', "La unidad {$libre} ya está retirada del inventario.");
    expect(referencia('AC-77812')->existencia)->toBe(1);

    retirarPorApi(['NO-EXISTE-1'])->assertUnprocessable()->assertJsonPath('codigo', 'NO-EXISTE-1');
});

it('RF-21 (b): la consulta previa de la pantalla rechaza con el mismo mensaje', function () {
    actuarComo(Rol::Logistica);
    [, $vendida] = venderComoMostrador('RN-4410');
    $libre = unidadDe('AC-77812');

    $this->getJson("/api/ajustes/unidad?tipo=baja&codigo={$libre->codigo_barras}")->assertOk()
        ->assertJsonPath('codigo_barras', $libre->codigo_barras)
        ->assertJsonPath('referencia.codigo_referencia', 'AC-77812')
        ->assertJsonPath('referencia.proveedor.nombre', 'Autopartes Central');

    $this->getJson("/api/ajustes/unidad?tipo=baja&codigo={$vendida}")->assertUnprocessable()
        ->assertJsonPath('message', "La unidad {$vendida} está vendida; no se puede retirar.");

    expect($libre->refresh()->estado->value)->toBe('disponible'); // consultar no escribe
});

it('RF-21 (c): con motivo otro, la nota es obligatoria', function () {
    actuarComo(Rol::Logistica);
    $codigo = unidadDe('RN-4410')->codigo_barras;

    retirarPorApi([$codigo], 'otro')->assertUnprocessable()->assertJsonValidationErrors('nota');
    retirarPorApi([$codigo], 'otro', '   ')->assertUnprocessable()->assertJsonValidationErrors('nota');
    expect(referencia('RN-4410')->existencia)->toBe(2);

    retirarPorApi([$codigo], 'otro', 'Se usó para pruebas del banco')->assertCreated()->assertJsonPath('nota', 'Se usó para pruebas del banco');
});

it('RF-21: el motivo debe corresponder al tipo y un código no se repite en el lote', function () {
    actuarComo(Rol::Logistica);
    $codigo = unidadDe('RN-4410')->codigo_barras;

    retirarPorApi([$codigo], 'error_de_registro')->assertUnprocessable()->assertJsonValidationErrors('motivo');
    retirarPorApi([$codigo, $codigo])->assertUnprocessable()->assertJsonValidationErrors('codigos.0.codigo');
    expect(AjusteInventario::count())->toBe(0);
});

it('RF-21 (d): reintegrar una unidad retirada la devuelve al inventario; una disponible responde 422', function () {
    $logistica = actuarComo(Rol::Logistica);
    $codigo = unidadDe('RN-4410')->codigo_barras;
    $bajaId = retirarPorApi([$codigo], 'dano')->json('id');

    $this->getJson('/api/ajustes?tipo=baja')->assertJsonPath('data.0.unidades.0.reintegrable', true);

    $this->postJson('/api/ajustes/reintegros', ['motivo' => 'error_de_registro', 'codigos' => loteAjuste([$codigo])])
        ->assertCreated()
        ->assertJsonPath('tipo', 'reintegro')
        ->assertJsonPath('motivo', 'error_de_registro')
        ->assertJsonPath('usuario.id', $logistica->id)
        ->assertJsonPath('unidades.0.estado', 'disponible');

    expect(Unidad::where('codigo_barras', $codigo)->first()->estado->value)->toBe('disponible')
        ->and(referencia('RN-4410')->existencia)->toBe(2);
    $this->assertDatabaseHas('movimientos_inventario', ['tipo' => 'reintegro', 'usuario_id' => $logistica->id, 'origen_type' => 'ajuste']);

    // Criterio 7: la baja y el reintegro quedan en el historial; la baja ya no se puede reintegrar.
    $this->getJson('/api/ajustes')->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.tipo', 'reintegro')
        ->assertJsonPath('data.1.id', $bajaId)
        ->assertJsonPath('data.1.unidades.0.reintegrable', false);

    $this->postJson('/api/ajustes/reintegros', ['motivo' => 'error_de_registro', 'codigos' => loteAjuste([$codigo])])
        ->assertUnprocessable()
        ->assertJsonPath('message', "La unidad {$codigo} ya está en inventario; no hay nada que reintegrar.");
    expect(referencia('RN-4410')->existencia)->toBe(2);
});

it('RF-21: no se reintegra una unidad de una referencia inactiva (S-26)', function () {
    actuarComo(Rol::Logistica);
    $codigos = Unidad::whereBelongsTo(referencia('RN-4410'))->pluck('codigo_barras')->all();
    retirarPorApi($codigos, 'perdida')->assertCreated();
    $this->postJson('/api/referencias/'.referencia('RN-4410')->id.'/desactivar')->assertOk();

    $this->postJson('/api/ajustes/reintegros', ['motivo' => 'error_de_registro', 'codigos' => loteAjuste([$codigos[0]])])
        ->assertUnprocessable();
    expect(referencia('RN-4410')->existencia)->toBe(0);
});

it('RF-21 (e) / criterio 4: una unidad retirada no se vende ni se escanea en mostrador', function () {
    actuarComo(Rol::Logistica);
    $codigo = unidadDe('RN-4410')->codigo_barras;
    retirarPorApi([$codigo])->assertCreated();

    actuarComo(Rol::Mostrador);
    $ventaId = $this->postJson('/api/ventas')->json('id');

    $this->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => $codigo, 'metodo_captura' => 'escaneado'])
        ->assertUnprocessable()
        ->assertJsonPath('message', "Unidad retirada del inventario: {$codigo}.");

    expect(Venta::find($ventaId)->items()->count())->toBe(0)
        ->and(referencia('RN-4410')->existencia)->toBe(1);

    // La búsqueda por código la muestra con su estado; no aparece entre las disponibles.
    $this->getJson("/api/buscar?q={$codigo}")->assertOk()->assertJsonPath('unidad.estado', 'retirada');
    $disponibles = collect($this->getJson('/api/buscar?q=RN-4410')->json('referencias.0.unidades_disponibles'))->pluck('codigo_barras');
    expect($disponibles)->not->toContain($codigo);
});

it('RF-21 / RF-08: una referencia sin disponibles por bajas aparece en agotados', function () {
    actuarComo(Rol::Logistica);
    retirarPorApi(Unidad::whereBelongsTo(referencia('RN-4410'))->pluck('codigo_barras')->all(), 'perdida')->assertCreated();

    $agotados = collect($this->getJson('/api/reportes/agotados?proveedor_id='.referencia('RN-4410')->proveedor_id)->assertOk()->json('data'))->pluck('codigo_referencia');
    expect($agotados)->toContain('RN-4410');
});

it('RF-21 (g) / criterio 8: mostrador recibe 403 en ajustes; logística y admin pasan', function () {
    actuarComo(Rol::Mostrador);
    $codigo = unidadDe('RN-4410')->codigo_barras;

    $this->getJson('/api/ajustes')->assertForbidden();
    $this->getJson("/api/ajustes/unidad?tipo=baja&codigo={$codigo}")->assertForbidden();
    retirarPorApi([$codigo])->assertForbidden();
    $this->postJson('/api/ajustes/reintegros', ['motivo' => 'error_de_registro', 'codigos' => loteAjuste([$codigo])])->assertForbidden();
    expect(referencia('RN-4410')->existencia)->toBe(2);

    actuarComo(Rol::Logistica);
    $this->getJson('/api/ajustes')->assertOk();

    actuarComo(Rol::Admin);
    $this->getJson('/api/ajustes')->assertOk();
    retirarPorApi([$codigo])->assertCreated();
});

it('RF-21 (h) / criterio 9: ajustes, ventas y movimientos no se borran ni se editan; no hay rutas DELETE', function () {
    actuarComo(Rol::Logistica);
    retirarPorApi([unidadDe('RN-4410')->codigo_barras])->assertCreated();

    $ajuste = AjusteInventario::firstOrFail();
    expect(fn () => $ajuste->delete())->toThrow(RegistroInmutableException::class)
        ->and(fn () => $ajuste->update(['nota' => 'cambio']))->toThrow(RegistroInmutableException::class)
        ->and(fn () => AjusteItem::firstOrFail()->delete())->toThrow(RegistroInmutableException::class)
        ->and(fn () => MovimientoInventario::where('tipo', 'baja')->firstOrFail()->delete())->toThrow(RegistroInmutableException::class);

    [$ventaId] = venderComoMostrador('AC-77812');
    expect(fn () => Venta::findOrFail($ventaId)->delete())->toThrow(RegistroInmutableException::class);

    $deletes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => in_array('DELETE', $r->methods(), true))
        ->map(fn ($r) => $r->uri())
        ->filter(fn ($uri) => str_contains($uri, 'ajuste') || str_contains($uri, 'devolucion') || str_contains($uri, 'venta') || str_contains($uri, 'movimiento'));
    expect($deletes)->toBeEmpty();

    $this->deleteJson("/api/ajustes/{$ajuste->id}")->assertNotFound();
    expect(AjusteInventario::count())->toBe(1);
});

it('RF-21 (i): el historial filtra por tipo, motivo y fecha', function () {
    actuarComo(Rol::Logistica);

    Carbon::setTestNow('2026-11-20 10:00:00');
    retirarPorApi([unidadDe('RN-4410')->codigo_barras], 'garantia')->assertCreated();
    Carbon::setTestNow('2026-11-22 15:00:00');
    retirarPorApi([unidadDe('AC-77812')->codigo_barras], 'dano')->assertCreated();
    Carbon::setTestNow();

    $this->getJson('/api/ajustes?motivo=garantia')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.motivo', 'garantia');
    $this->getJson('/api/ajustes?motivo=perdida')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/ajustes?tipo=reintegro')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/ajustes?desde=2026-11-21&hasta=2026-11-22')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.motivo', 'dano');
    $this->getJson('/api/ajustes?desde=2026-11-20&hasta=2026-11-20')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.motivo', 'garantia');
    $this->getJson('/api/ajustes?desde=2026-11-22&hasta=2026-11-20')->assertUnprocessable();
    $this->getJson('/api/ajustes?motivo=inventado')->assertUnprocessable();
});

it('RF-21 (i): desde y hasta son días de Bogotá; hasta incluye el día completo', function () {
    actuarComo(Rol::Logistica);

    // 20-nov 21:30 en Bogotá = 21-nov 02:30 en UTC (zona de la app).
    Carbon::setTestNow(Carbon::parse('2026-11-20 21:30:00', 'America/Bogota')->utc());
    retirarPorApi([unidadDe('RN-4410')->codigo_barras], 'garantia')->assertCreated();
    // 20-nov 23:59:59 en Bogotá: último segundo del día.
    Carbon::setTestNow(Carbon::parse('2026-11-20 23:59:59', 'America/Bogota')->utc());
    retirarPorApi([unidadDe('AC-77812')->codigo_barras], 'dano')->assertCreated();
    // 21-nov 00:00:00 en Bogotá: ya es otro día.
    Carbon::setTestNow(Carbon::parse('2026-11-21 00:00:00', 'America/Bogota')->utc());
    retirarPorApi([unidadDe('DE-IM204-A')->codigo_barras], 'perdida')->assertCreated();
    Carbon::setTestNow();

    $this->getJson('/api/ajustes?desde=2026-11-20&hasta=2026-11-20')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/ajustes?hasta=2026-11-20')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/ajustes?desde=2026-11-21')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.motivo', 'perdida');
});
