<?php

use App\Enums\Rol;
use App\Exceptions\RegistroInmutableException;
use App\Models\Cliente;
use App\Models\Venta;
use App\Models\VentaItem;
use Illuminate\Support\Carbon;

beforeEach(fn () => sembrarDemo());

it('RF-14: la venta de contado sigue abriéndose sin cliente y sin vencimiento', function () {
    actuarComo(Rol::Mostrador);

    // S-46: la venta nace con su primer escaneo.
    $id = $this->postJson('/api/ventas', escaneoInicial('RN-4410'))->assertCreated()->json('venta_id');
    $this->getJson("/api/ventas/{$id}")->assertJsonPath('forma_pago', 'contado')->assertJsonPath('cliente', null);

    cerrarVenta($id)->assertOk()->assertJsonPath('vence_el', null);
});

it('RF-14: una venta a crédito sin cliente responde 422', function () {
    actuarComo(Rol::Mostrador);

    $this->postJson('/api/ventas', ['forma_pago' => 'credito', ...escaneoInicial('RN-4410')])->assertUnprocessable();
    expect(Venta::count())->toBe(0);
});

it('RF-14: una venta a crédito con un cliente inactivo responde 422', function () {
    actuarComo(Rol::Mostrador);
    $cliente = Cliente::factory()->inactivo()->create();

    $this->postJson('/api/ventas', ['forma_pago' => 'credito', 'cliente_id' => $cliente->id, ...escaneoInicial('RN-4410')])->assertUnprocessable();
    expect(Venta::count())->toBe(0);
});

it('RF-14: forma_pago inválida o cliente inexistente no pasan la validación', function () {
    actuarComo(Rol::Mostrador);

    $this->postJson('/api/ventas', ['forma_pago' => 'fiado', ...escaneoInicial('RN-4410')])->assertUnprocessable()->assertJsonValidationErrors('forma_pago');
    $this->postJson('/api/ventas', ['forma_pago' => 'credito', 'cliente_id' => 999999, ...escaneoInicial('RN-4410')])->assertUnprocessable()->assertJsonValidationErrors('cliente_id');
});

it('RF-14: escanear guarda el precio de venta y el IVA de la referencia en el ítem (S-10, S-15)', function () {
    actuarComo(Rol::Mostrador);
    $cliente = Cliente::factory()->create();
    // S-46: POST /ventas crea la venta a crédito con su primer escaneo.
    $id = $this->postJson('/api/ventas', ['forma_pago' => 'credito', 'cliente_id' => $cliente->id, 'factura_contapyme' => 'FE-1234', ...escaneoInicial('RN-4410')])
        ->assertCreated()
        ->json('venta_id');
    $this->getJson("/api/ventas/{$id}")
        ->assertJsonPath('forma_pago', 'credito')
        ->assertJsonPath('cliente.id', $cliente->id)
        ->assertJsonPath('factura_contapyme', 'FE-1234');

    $item = VentaItem::where('venta_id', $id)->sole();
    expect($item->precio_unitario)->toBe('85000.00')
        ->and($item->iva_porcentaje)->toBe('19.00');

    // Si después cambia el precio de la referencia, el ítem vendido conserva el suyo.
    referencia('RN-4410')->update(['precio_venta' => 99000]);
    $this->getJson("/api/ventas/{$id}")->assertJsonPath('items.0.precio_unitario', '85000.00');
});

it('RF-14: una referencia sin IVA se vende con iva_porcentaje 0', function () {
    actuarComo(Rol::Mostrador);
    referencia('RN-4410')->update(['iva_venta_porcentaje' => 0]);
    $id = abrirVenta();

    $this->postJson("/api/ventas/{$id}/escanear", ['codigo_barras' => unidadDe('RN-4410')->codigo_barras, 'metodo_captura' => 'digitado'])
        ->assertCreated();

    expect(VentaItem::where('venta_id', $id)->value('iva_porcentaje'))->toBe('0.00');
});

it('RF-14: al cerrar una venta a crédito, vence_el = fecha de cierre + plazo del cliente (S-11)', function () {
    Carbon::setTestNow('2026-11-20 16:45:00');
    actuarComo(Rol::Mostrador);
    $cliente = Cliente::factory()->create(['plazo_dias' => 45]);
    $id = abrirVenta(['forma_pago' => 'credito', 'cliente_id' => $cliente->id]);

    expect(Venta::find($id)->vence_el)->toBeNull();

    cerrarVenta($id)->assertOk()->assertJsonPath('vence_el', '2027-01-04');
});

it('RF-14: forma_pago, cliente y factura_contapyme no se editan después de crear la venta', function () {
    $mostrador = actuarComo(Rol::Mostrador);
    $cliente = Cliente::factory()->create();
    $venta = Venta::create(['usuario_id' => $mostrador->id]);

    $venta->forma_pago = 'credito';
    $venta->cliente_id = $cliente->id;
    expect(fn () => $venta->save())->toThrow(RegistroInmutableException::class);

    $venta->refresh();
    $venta->factura_contapyme = 'FE-1';
    expect(fn () => $venta->save())->toThrow(RegistroInmutableException::class);
});

it('RF-14: vence_el solo se fija en el cierre, no después', function () {
    $mostrador = actuarComo(Rol::Mostrador);
    $cliente = Cliente::factory()->create();
    $venta = Venta::create(['usuario_id' => $mostrador->id, 'forma_pago' => 'credito', 'cliente_id' => $cliente->id]);

    $venta->vence_el = '2030-01-01';
    expect(fn () => $venta->save())->toThrow(RegistroInmutableException::class);

    cerrarVenta($venta->id)->assertOk();
    $cerrada = Venta::find($venta->id);
    $cerrada->vence_el = '2030-01-01';
    expect(fn () => $cerrada->save())->toThrow(RegistroInmutableException::class);
});
