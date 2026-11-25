<?php

use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\Proveedor;
use Illuminate\Support\Carbon;

beforeEach(fn () => sembrarDemo());

/* ---------- RF-29: rol contable ---------- */

it('RF-29: el contable consulta cuentas por pagar y registra un pago, pero no entra a operación, usuarios ni anulaciones', function () {
    $ref = referencia('RN-4410');
    compra($ref, 1, 500, aCredito());
    actuarComo(Rol::Contable);

    $this->getJson('/api/reportes/cuentas-por-pagar')->assertOk()->assertJsonPath('totales.saldo', '500.00');
    $this->getJson('/api/reportes/cartera-clientes')->assertOk();
    $this->getJson('/api/proveedores')->assertOk();
    $this->getJson('/api/clientes')->assertOk();
    $this->getJson('/api/me')->assertOk();
    $pago = $this->postJson("/api/proveedores/{$ref->proveedor_id}/pagos", ['valor' => 100, 'cuenta_pago_id' => cuentaBanco()->id])
        ->assertCreated()->json('id');

    $this->getJson('/api/ventas')->assertForbidden();
    $this->getJson('/api/ingresos')->assertForbidden();
    $this->getJson('/api/usuarios')->assertForbidden();
    $this->getJson('/api/referencias')->assertForbidden();
    $this->getJson('/api/auditoria')->assertForbidden();
    $this->postJson("/api/pagos/{$pago}/anulaciones", ['motivo' => 'Error'])->assertForbidden();
});

it('RF-29: logística y mostrador no entran al módulo contable', function (Rol $rol) {
    actuarComo($rol);

    $this->getJson('/api/vencimientos')->assertForbidden();
    $this->getJson('/api/reportes/cuentas-por-pagar')->assertForbidden();
})->with([Rol::Logistica, Rol::Mostrador]);

it('RF-29: el admin crea una cuenta contable', function () {
    actuarComo(Rol::Admin);

    $this->postJson('/api/usuarios', ['name' => 'Auxiliar', 'usuario' => 'auxiliar', 'password' => 'clave-segura-1', 'rol' => 'contable'])
        ->assertCreated()->assertJsonPath('rol', 'contable');
});

/* ---------- RF-17 (ajuste): pago a una factura elegida ---------- */

it('RF-17 ajuste: el pago a una factura elegida la cancela; sin elegir, se aplica FIFO', function () {
    Carbon::setTestNow('2026-11-23 10:00:00');
    $ref = referencia('RN-4410');
    $a = compra($ref, 1, 100, aCredito('2026-12-01'));
    $b = compra($ref, 1, 200, aCredito('2026-12-15'));
    actuarComo(Rol::Contable);
    $url = "/api/proveedores/{$ref->proveedor_id}";

    $pago = $this->postJson("{$url}/pagos", ['valor' => 200, 'cuenta_pago_id' => cuentaBanco()->id, 'ingreso_id' => $b->id])
        ->assertCreated()->assertJsonPath('ingreso_id', $b->id)->assertJsonPath('ingreso.id', $b->id)->json('id');

    $saldo = $this->getJson("{$url}/saldo")->assertJsonPath('saldo', '100.00');
    expect(collect($saldo->json('documentos'))->pluck('pendiente', 'id')->all())->toBe([$a->id => '100.00']);

    // Anular el pago a B devuelve su pendiente a 200.
    actuarComo(Rol::Admin);
    $this->postJson("/api/pagos/{$pago}/anulaciones", ['motivo' => 'Transferencia devuelta'])->assertCreated();

    // El mismo pago sin elegir factura cancela A (vence primero) y deja B en 100.
    $this->postJson("{$url}/pagos", ['valor' => 200, 'cuenta_pago_id' => cuentaBanco()->id])->assertCreated();
    $saldo = $this->getJson("{$url}/saldo")->assertJsonPath('saldo', '100.00');
    expect(collect($saldo->json('documentos'))->pluck('pendiente', 'id')->all())->toBe([$b->id => '100.00']);
});

it('RF-17 ajuste: se admiten pagos parciales a una factura hasta su pendiente', function () {
    $ref = referencia('RN-4410');
    $b = compra($ref, 1, 200, aCredito());
    actuarComo(Rol::Contable);
    $url = "/api/proveedores/{$ref->proveedor_id}/pagos";

    $this->postJson($url, ['valor' => 150, 'cuenta_pago_id' => cuentaEfectivo()->id, 'ingreso_id' => $b->id])->assertCreated();
    $this->postJson($url, ['valor' => 51, 'cuenta_pago_id' => cuentaEfectivo()->id, 'ingreso_id' => $b->id])->assertUnprocessable();
    $this->postJson($url, ['valor' => 50, 'cuenta_pago_id' => cuentaEfectivo()->id, 'ingreso_id' => $b->id])->assertCreated();
    $this->postJson($url, ['valor' => 1, 'cuenta_pago_id' => cuentaEfectivo()->id, 'ingreso_id' => $b->id])
        ->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'cancelado'));
});

it('RF-17 ajuste: se rechaza pagar más del pendiente, a otro proveedor, de contado o en borrador', function () {
    $ref = referencia('RN-4410');
    $b = compra($ref, 1, 200, aCredito());
    $contado = compra($ref, 1, 200);
    $borrador = compra($ref, 1, 200, aCredito(), confirmar: false);
    $otro = Proveedor::where('id', '!=', $ref->proveedor_id)->firstOrFail();
    actuarComo(Rol::Contable);
    $url = "/api/proveedores/{$ref->proveedor_id}/pagos";

    $this->postJson($url, ['valor' => 201, 'cuenta_pago_id' => cuentaEfectivo()->id, 'ingreso_id' => $b->id])->assertUnprocessable();
    $this->postJson("/api/proveedores/{$otro->id}/pagos", ['valor' => 10, 'cuenta_pago_id' => cuentaEfectivo()->id, 'ingreso_id' => $b->id])->assertUnprocessable();
    $this->postJson($url, ['valor' => 10, 'cuenta_pago_id' => cuentaEfectivo()->id, 'ingreso_id' => $contado->id])->assertUnprocessable();
    $this->postJson($url, ['valor' => 10, 'cuenta_pago_id' => cuentaEfectivo()->id, 'ingreso_id' => $borrador->id])->assertUnprocessable();

    $this->getJson("/api/proveedores/{$ref->proveedor_id}/saldo")->assertJsonPath('abonos', '0.00');
});

/* ---------- RF-30: avisos de vencimiento ---------- */

it('RF-30: con aviso de 1 día salen las vencidas y las que vencen mañana; ni las lejanas ni las pagadas', function () {
    config(['cartera.aviso_dias' => 1]);
    Carbon::setTestNow('2026-11-10 10:00:00');
    $ref = referencia('RN-4410');
    $vencida = compra($ref, 1, 300, aCredito('2026-11-20'));
    $manana = compra($ref, 1, 200, aCredito('2026-11-24'));
    compra($ref, 1, 100, aCredito('2026-11-26'));
    $pagada = compra($ref, 1, 50, aCredito('2026-11-19'));
    Carbon::setTestNow('2026-11-23 10:00:00');
    actuarComo(Rol::Contable);
    $this->postJson("/api/proveedores/{$ref->proveedor_id}/pagos", ['valor' => 50, 'cuenta_pago_id' => cuentaEfectivo()->id, 'ingreso_id' => $pagada->id])->assertCreated();

    $r = $this->getJson('/api/vencimientos?tipo=por_pagar')->assertOk()
        ->assertJsonPath('aviso_dias', 1)
        ->assertJsonPath('totales.vencidas.cantidad', 1)
        ->assertJsonPath('totales.por_vencer.cantidad', 1)
        ->assertJsonPath('totales.todas.pendiente', '500.00')
        ->assertJsonPath('data.0.documento_id', $vencida->id)
        ->assertJsonPath('data.0.estado', 'vencida')
        ->assertJsonPath('data.0.dias_vencido', 3)
        ->assertJsonPath('data.1.documento_id', $manana->id)
        ->assertJsonPath('data.1.estado', 'por_vencer')
        ->assertJsonPath('data.1.dias_para_vencer', 1);

    expect($r->json('total'))->toBe(2);
});

it('RF-30: también avisa las ventas a crédito de clientes vencidas, netas de abonos', function () {
    config(['cartera.aviso_dias' => 1]);
    $cliente = Cliente::create(['nombre' => 'Taller El Pistón', 'plazo_dias' => 30]);
    Carbon::setTestNow('2026-10-01 10:00:00');
    $venta = ventaCreditoCerrada($cliente, 2, 50000); // vence el 31-oct
    Carbon::setTestNow('2026-11-23 10:00:00');
    actuarComo(Rol::Admin);
    $this->postJson("/api/clientes/{$cliente->id}/abonos", ['valor' => 30000, 'cuenta_pago_id' => cuentaEfectivo()->id])->assertCreated();

    actuarComo(Rol::Contable);
    $this->getJson('/api/vencimientos?tipo=por_cobrar')->assertOk()
        ->assertJsonPath('data.0.tipo', 'por_cobrar')
        ->assertJsonPath('data.0.titular.nombre', 'Taller El Pistón')
        ->assertJsonPath('data.0.documento', "Venta #{$venta->id}")
        ->assertJsonPath('data.0.estado', 'vencida')
        ->assertJsonPath('data.0.pendiente', '70000.00');
});
