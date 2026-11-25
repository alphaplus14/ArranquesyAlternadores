<?php

use App\Enums\Rol;
use App\Exceptions\RegistroInmutableException;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\MovimientoInventario;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaItem;

beforeEach(fn () => sembrarDemo());

/** Abre una venta (a crédito por defecto) y escanea una unidad de la referencia; devuelve el ítem. */
function itemVendido(string $codigoReferencia = 'RN-4410', bool $credito = true): VentaItem
{
    $test = test();
    $datos = $credito ? ['forma_pago' => 'credito', 'cliente_id' => Cliente::factory()->create()->id] : [];
    $ventaId = abrirVenta($datos);

    $test->postJson("/api/ventas/{$ventaId}/escanear", [
        'codigo_barras' => unidadDe($codigoReferencia)->codigo_barras,
        'metodo_captura' => 'escaneado',
    ])->assertCreated();

    return VentaItem::where('venta_id', $ventaId)->latest('id')->firstOrFail();
}

function cambiarPrecio(VentaItem $item, mixed $precio)
{
    return test()->patchJson("/api/ventas/{$item->venta_id}/items/{$item->id}", ['precio_unitario' => $precio]);
}

it('RF-14: en una venta a crédito abierta se cambia el precio de un ítem y queda auditado', function () {
    $mostrador = actuarComo(Rol::Mostrador);
    $item = itemVendido();

    cambiarPrecio($item, 72500.5)
        ->assertOk()
        ->assertJsonPath('id', $item->venta_id)
        ->assertJsonPath('items.0.precio_unitario', '72500.50');

    expect($item->fresh()->precio_unitario)->toBe('72500.50');

    $auditoria = Auditoria::where('tabla', 'venta_items')->where('registro_id', $item->id)->sole();
    expect($auditoria->campo)->toBe('precio_unitario')
        ->and($auditoria->valor_anterior)->toBe('85000.00')
        ->and($auditoria->valor_nuevo)->toBe('72500.50')
        ->and($auditoria->usuario_id)->toBe($mostrador->id)
        ->and($auditoria->created_at)->not->toBeNull();
});

it('RF-14: el precio libre no tiene piso: puede quedar por debajo del precio estándar', function () {
    actuarComo(Rol::Mostrador);
    $item = itemVendido();

    cambiarPrecio($item, '0.01')->assertOk()->assertJsonPath('items.0.precio_unitario', '0.01');
});

it('RF-14 (S-43): el admin no cambia precios: solo el mostrador vende (403)', function () {
    actuarComo(Rol::Mostrador);
    $item = itemVendido();

    actuarComo(Rol::Admin);
    cambiarPrecio($item, 90000)->assertForbidden()->assertJsonPath('message', 'Su rol no tiene acceso a este módulo.');
    expect($item->fresh()->precio_unitario)->toBe('85000.00');
});

it('RF-14: cambiar precio en una venta de contado responde 422 y no audita', function () {
    actuarComo(Rol::Mostrador);
    $item = itemVendido(credito: false);

    cambiarPrecio($item, 70000)->assertStatus(422);
    expect($item->fresh()->precio_unitario)->toBe('85000.00')
        ->and(Auditoria::where('tabla', 'venta_items')->count())->toBe(0);
});

it('RF-14: cambiar precio en una venta a crédito cerrada responde 422', function () {
    actuarComo(Rol::Mostrador);
    $item = itemVendido();
    cerrarVenta($item->venta_id)->assertOk();

    cambiarPrecio($item, 70000)->assertStatus(422);
    expect($item->fresh()->precio_unitario)->toBe('85000.00');
});

it('RF-14: cambiar precio de un ítem reversado responde 422', function () {
    actuarComo(Rol::Mostrador);
    $item = itemVendido();
    $this->postJson("/api/ventas/{$item->venta_id}/reversos", [
        'tipo' => 'anulacion', 'motivo' => 'Se equivocó de pieza', 'venta_item_id' => $item->id,
    ])->assertOk();

    cambiarPrecio($item, 70000)->assertStatus(422);
    expect($item->fresh()->precio_unitario)->toBe('85000.00');
});

it('RF-14: un precio 0, negativo o vacío responde 422', function (mixed $precio) {
    actuarComo(Rol::Mostrador);
    $item = itemVendido();

    cambiarPrecio($item, $precio)->assertUnprocessable()->assertJsonValidationErrors('precio_unitario');
    expect($item->fresh()->precio_unitario)->toBe('85000.00');
})->with([0, '0.00', -500, null, 'abc']);

it('RF-14: logística no puede cambiar precios (403)', function () {
    actuarComo(Rol::Mostrador);
    $item = itemVendido();

    actuarComo(Rol::Logistica);
    cambiarPrecio($item, 70000)->assertForbidden();
    expect($item->fresh()->precio_unitario)->toBe('85000.00');
});

it('RF-14: un ítem de otra venta responde 404', function () {
    actuarComo(Rol::Mostrador);
    $item = itemVendido('RN-4410');
    $otra = itemVendido('DE-IM204-A');

    $this->patchJson("/api/ventas/{$otra->venta_id}/items/{$item->id}", ['precio_unitario' => 70000])->assertNotFound();
    expect($item->fresh()->precio_unitario)->toBe('85000.00');
});

it('RF-14: fuera del precio en crédito abierto, venta_items sigue inmutable', function () {
    actuarComo(Rol::Mostrador);
    $credito = itemVendido('RN-4410');
    $contado = itemVendido('DE-IM204-A', credito: false);

    // Otro campo, aun con la venta a crédito abierta.
    expect(fn () => $credito->update(['metodo_captura' => 'digitado']))->toThrow(RegistroInmutableException::class);
    expect(fn () => $credito->update(['precio_unitario' => 1, 'iva_porcentaje' => 0]))->toThrow(RegistroInmutableException::class);
    expect(fn () => $credito->update(['usuario_id' => User::factory()->create()->id]))->toThrow(RegistroInmutableException::class);
    // El precio en una venta de contado.
    expect(fn () => $contado->update(['precio_unitario' => 1]))->toThrow(RegistroInmutableException::class);
    // Borrar, nunca.
    expect(fn () => $credito->delete())->toThrow(RegistroInmutableException::class);

    // El precio de la venta a crédito ya cerrada.
    cerrarVenta($credito->venta_id)->assertOk();
    expect(fn () => $credito->fresh()->update(['precio_unitario' => 1]))->toThrow(RegistroInmutableException::class);
    expect(Venta::findOrFail($credito->venta_id)->estaAbierta())->toBeFalse();
});

it('RF-14: cambiar el precio no altera existencia ni movimientos de inventario', function () {
    actuarComo(Rol::Mostrador);
    $item = itemVendido();
    $existencia = referencia('RN-4410')->existencia;
    $movimientos = MovimientoInventario::count();
    $estadoUnidad = $item->unidad->estado;

    cambiarPrecio($item, 50000)->assertOk();

    expect(referencia('RN-4410')->existencia)->toBe($existencia)
        ->and(MovimientoInventario::count())->toBe($movimientos)
        ->and($item->unidad->fresh()->estado)->toBe($estadoUnidad);
});

it('RF-11 / RF-14: el cambio de precio se consulta en el historial de auditoría de venta_items', function () {
    actuarComo(Rol::Mostrador);
    $item = itemVendido();
    cambiarPrecio($item, 60000)->assertOk();

    actuarComo(Rol::Admin);
    $this->getJson("/api/auditoria?tabla=venta_items&registro_id={$item->id}")
        ->assertOk()
        ->assertJsonPath('data.0.campo', 'precio_unitario')
        ->assertJsonPath('data.0.valor_nuevo', '60000.00');
});
