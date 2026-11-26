<?php

use App\Enums\Rol;
use App\Exceptions\RegistroInmutableException;
use App\Models\AbonoCliente;
use App\Models\Cliente;
use App\Models\CuentaPago;
use App\Models\PagoProveedor;
use App\Models\VentaPago;

/*
 * RF-24 (S-48): cuentas de pago y forma de pago de la venta de contado. Abonos y pagos eligen la
 * cuenta; el medio se deriva de su tipo.
 */

beforeEach(function () {
    sembrarDemo();
    referencia('RN-4410')->update(['precio_venta' => 50000]);
});

/** Venta de contado abierta con N unidades de RN-4410 (50.000 c/u), como el mostrador en sesión. */
function ventaContadoAbierta(int $unidades): int
{
    $id = abrirVenta();
    for ($i = 0; $i < $unidades; $i++) {
        test()->postJson("/api/ventas/{$id}/escanear", ['codigo_barras' => unidadDe('RN-4410')->codigo_barras, 'metodo_captura' => 'escaneado'])->assertCreated();
    }

    return $id;
}

function cuenta(string $nombre): CuentaPago
{
    return CuentaPago::where('nombre', $nombre)->firstOrFail();
}

it('RF-24: la migración crea la cuenta Efectivo y el seeder las demás cuentas', function () {
    expect(cuentaEfectivo()->tipo->value)->toBe('efectivo')
        ->and(CuentaPago::pluck('tipo', 'nombre')->map->value->all())->toMatchArray([
            'Efectivo' => 'efectivo', 'Bancolombia' => 'banco', 'Caja Social' => 'banco', 'Datáfono' => 'datafono',
            'Nequi' => 'billetera', 'Daviplata' => 'billetera', 'Llave Dahianna' => 'billetera', 'Llave Evelin' => 'billetera',
        ]);
});

it('RF-24 / S-48: la venta de contado se cierra con el pago exacto y lo guarda', function () {
    actuarComo(Rol::Mostrador);
    $id = ventaContadoAbierta(2);

    cerrarVenta($id, [['cuenta_pago_id' => cuentaEfectivo()->id, 'valor' => 100000]])->assertOk()
        ->assertJsonPath('estado', 'cerrada')
        ->assertJsonPath('pagos.0.cuenta.nombre', 'Efectivo')
        ->assertJsonPath('pagos.0.valor', '100000.00');

    expect(VentaPago::where('venta_id', $id)->sum('valor'))->toEqual(100000);
});

it('RF-24 / S-48: el pago se puede dividir entre varias cuentas, con referencia opcional', function () {
    actuarComo(Rol::Mostrador);
    $id = ventaContadoAbierta(2);

    cerrarVenta($id, [
        ['cuenta_pago_id' => cuentaEfectivo()->id, 'valor' => 60000],
        ['cuenta_pago_id' => cuenta('Nequi')->id, 'valor' => 40000, 'referencia' => 'NQ-123'],
    ])->assertOk()->assertJsonCount(2, 'pagos')->assertJsonPath('pagos.1.referencia', 'NQ-123');
});

it('RF-24 / S-48: si los pagos no suman exactamente el total, la venta no se cierra', function () {
    actuarComo(Rol::Mostrador);
    $id = ventaContadoAbierta(2);

    cerrarVenta($id, [['cuenta_pago_id' => cuentaEfectivo()->id, 'valor' => 90000]])->assertUnprocessable()
        ->assertJsonPath('message', 'Los pagos suman menos que el total de la venta: faltan $10.000.');
    cerrarVenta($id, [['cuenta_pago_id' => cuentaEfectivo()->id, 'valor' => 60000], ['cuenta_pago_id' => cuenta('Nequi')->id, 'valor' => 50000]])
        ->assertUnprocessable()->assertJsonPath('message', 'Los pagos suman más que el total de la venta: sobran $10.000.');
    $this->postJson("/api/ventas/{$id}/cerrar")->assertUnprocessable()
        ->assertJsonPath('message', 'Indique la forma de pago: al menos una cuenta con su valor.');
    cerrarVenta($id, [['cuenta_pago_id' => cuentaEfectivo()->id, 'valor' => 0]])->assertUnprocessable()->assertJsonValidationErrors('pagos.0.valor');

    cuenta('Nequi')->update(['activa' => false]);
    cerrarVenta($id, [['cuenta_pago_id' => cuenta('Nequi')->id, 'valor' => 100000]])->assertUnprocessable()
        ->assertJsonPath('message', 'Alguna cuenta del pago no existe o está inactiva.');

    $this->getJson("/api/ventas/{$id}")->assertJsonPath('estado', 'abierta');
    expect(VentaPago::where('venta_id', $id)->count())->toBe(0);
});

it('RF-24 / S-48: una venta a crédito rechaza pagos al cerrar y se cierra sin ellos', function () {
    $cliente = Cliente::factory()->create();
    [$id] = ventaCredito($cliente, [70000], cerrar: false);
    actuarComo(Rol::Mostrador);

    cerrarVenta($id, [['cuenta_pago_id' => cuentaEfectivo()->id, 'valor' => 70000]])->assertUnprocessable()
        ->assertJsonPath('message', 'Una venta a crédito no recibe pagos al cerrarla: el cliente abona después en Cartera.');
    cerrarVenta($id, [])->assertOk()->assertJsonPath('estado', 'cerrada')->assertJsonCount(0, 'pagos');
});

it('RF-24: los pagos de una venta son inmutables', function () {
    actuarComo(Rol::Mostrador);
    $id = ventaContadoAbierta(1);
    cerrarVenta($id)->assertOk();
    $pago = VentaPago::where('venta_id', $id)->firstOrFail();

    $pago->valor = 1;
    expect(fn () => $pago->save())->toThrow(RegistroInmutableException::class)
        ->and(fn () => $pago->delete())->toThrow(RegistroInmutableException::class);
});

it('RF-24 / S-48: el abono elige la cuenta y el medio se deriva de su tipo', function () {
    $cliente = Cliente::factory()->create();
    ventaCredito($cliente, [5000]);
    actuarComo(Rol::Mostrador);

    abonar($cliente, 1000)->assertCreated()->assertJsonPath('abono.medio', 'efectivo')->assertJsonPath('abono.cuenta.nombre', 'Efectivo');
    abonar($cliente, 1000, ['cuenta_pago_id' => cuenta('Daviplata')->id])->assertCreated()->assertJsonPath('abono.medio', 'transferencia');
    $this->postJson("/api/clientes/{$cliente->id}/abonos", ['valor' => 1000, 'medio' => 'efectivo'])->assertUnprocessable()->assertJsonValidationErrors('cuenta_pago_id');

    expect(AbonoCliente::where('cliente_id', $cliente->id)->whereNotNull('cuenta_pago_id')->count())->toBe(2);
});

it('RF-24 / S-48: el pago a proveedor elige la cuenta y el medio se deriva de su tipo', function () {
    $ref = referencia('RN-4410');
    compra($ref, 1, 1000, aCredito());
    actuarComo(Rol::Admin);

    $id = pagar($ref->proveedor_id, 500, ['cuenta_pago_id' => cuenta('Caja Social')->id])
        ->assertCreated()->assertJsonPath('medio', 'transferencia')->assertJsonPath('cuenta.nombre', 'Caja Social')->json('id');
    expect(PagoProveedor::find($id)->cuenta_pago_id)->toBe(cuenta('Caja Social')->id);

    pagar($ref->proveedor_id, 500)->assertCreated()->assertJsonPath('medio', 'efectivo');
});

it('RF-24: el mostrador ve las cuentas activas; solo el admin las crea, edita y desactiva', function () {
    actuarComo(Rol::Admin);
    $id = $this->postJson('/api/cuentas-pago', ['nombre' => 'Banco de Bogotá', 'tipo' => 'banco'])->assertCreated()->json('id');
    $this->putJson("/api/cuentas-pago/{$id}", ['activa' => false])->assertOk()->assertJsonPath('activa', false);
    $this->postJson('/api/cuentas-pago', ['nombre' => 'Nequi', 'tipo' => 'billetera'])->assertUnprocessable()->assertJsonValidationErrors('nombre');
    $this->getJson('/api/cuentas-pago?incluir_inactivas=1')->assertOk()->assertJsonFragment(['nombre' => 'Banco de Bogotá', 'activa' => false]);
    // La caja necesita una cuenta de efectivo activa.
    $this->putJson('/api/cuentas-pago/'.cuentaEfectivo()->id, ['activa' => false])->assertUnprocessable();

    actuarComo(Rol::Mostrador);
    $nombres = collect($this->getJson('/api/cuentas-pago?incluir_inactivas=1')->assertOk()->json('data'))->pluck('nombre');
    expect($nombres)->toContain('Efectivo')->not->toContain('Banco de Bogotá');
    $this->postJson('/api/cuentas-pago', ['nombre' => 'Otra', 'tipo' => 'banco'])->assertForbidden();
    $this->putJson("/api/cuentas-pago/{$id}", ['activa' => true])->assertForbidden();

    actuarComo(Rol::Logistica);
    $this->getJson('/api/cuentas-pago')->assertForbidden();
});

it('RF-24: la edición de una cuenta queda en la auditoría', function () {
    actuarComo(Rol::Admin);
    $this->putJson('/api/cuentas-pago/'.cuenta('Nequi')->id, ['nombre' => 'Nequi local'])->assertOk();

    $this->assertDatabaseHas('auditoria', ['tabla' => 'cuentas_pago', 'campo' => 'nombre', 'valor_anterior' => 'Nequi', 'valor_nuevo' => 'Nequi local']);
});
