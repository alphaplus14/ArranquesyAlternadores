<?php

use App\Enums\Rol;
use App\Models\AbonoCliente;
use App\Models\Cliente;
use App\Models\PagoProveedor;
use App\Services\CarteraService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * S-79 (RF-15, RF-17, RF-29; ajusta S-29, S-32 y S-36): todo pago a proveedor va a una factura o
 * remisión a crédito y todo abono de cliente a una venta a crédito cerrada. Varios pagos o abonos
 * parciales sucesivos al mismo documento hasta cancelarlo; nunca más que su pendiente (sin saldo a
 * favor nuevo). Lo anterior sin documento se sigue leyendo, sumando y anulando.
 */

beforeEach(fn () => sembrarDemo());

/** Pendiente y abonado de un documento del proveedor (null si ya no tiene saldo). */
function s79Documento(int $proveedorId, int $ingresoId): ?array
{
    return collect(test()->getJson("/api/proveedores/{$proveedorId}/saldo")->assertOk()->json('documentos'))->firstWhere('id', $ingresoId);
}

/** Venta de solo valor (S-73) registrada por el mostrador. */
function s79VentaSoloValor(Cliente $cliente, int $valor): int
{
    $anterior = auth()->user();
    actuarComo(Rol::Mostrador);
    $id = test()->postJson('/api/ventas/credito', ['cliente_id' => $cliente->id, 'tipo_documento' => 'factura', 'valor_total' => $valor, 'factura_contapyme' => 'FE-79'])
        ->assertCreated()->json('id');
    $anterior ? test()->actingAs($anterior) : null;

    return $id;
}

/* ───────────── Pagos a proveedores ───────────── */

it('S-79: un pago sin factura responde 422 y no crea nada', function () {
    $ref = referencia('RN-4410');
    compra($ref, 1, 500, aCredito());
    actuarComo(Rol::Contable);

    $this->postJson("/api/proveedores/{$ref->proveedor_id}/pagos", ['valor' => 100, 'cuenta_pago_id' => cuentaEfectivo()->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['ingreso_id' => 'Elija la factura que va a pagar.']);

    expect(PagoProveedor::count())->toBe(0);
});

it('S-79: un pago mayor al pendiente responde 422 con el pendiente', function () {
    $ref = referencia('RN-4410');
    $factura = compra($ref, 1, 500, aCredito());
    actuarComo(Rol::Contable);

    pagar($ref->proveedor_id, 501, ['ingreso_id' => $factura->id])->assertUnprocessable()
        ->assertJsonPath('campo', 'valor')
        ->assertJsonPath('pendiente', '500.00')
        ->assertJsonPath('message', 'El pago supera lo pendiente de ese documento ($500).');
});

it('S-79: pagos parciales sucesivos: el primero deja saldo y "abonado", el segundo cancela y un tercero se rechaza', function () {
    $ref = referencia('RN-4410');
    $factura = compra($ref, 1, 500, aCredito());
    actuarComo(Rol::Contable);

    pagar($ref->proveedor_id, 200, ['ingreso_id' => $factura->id])->assertCreated();
    expect(s79Documento($ref->proveedor_id, $factura->id))->toMatchArray(['valor' => '500.00', 'abonado' => '200.00', 'pendiente' => '300.00']);

    pagar($ref->proveedor_id, 300, ['ingreso_id' => $factura->id])->assertCreated();
    expect(s79Documento($ref->proveedor_id, $factura->id))->toBeNull();

    pagar($ref->proveedor_id, 1, ['ingreso_id' => $factura->id])->assertUnprocessable()
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'cancelado'));

    // Cada pago parcial es una línea propia del estado de cuenta, con su factura; el saldo cuadra.
    actuarComo(Rol::Admin);
    $pagos = collect($this->getJson("/api/proveedores/{$ref->proveedor_id}/estado-cuenta")->assertOk()->json('data'))->where('tipo', 'pago');
    expect($pagos)->toHaveCount(2);
    $this->getJson("/api/proveedores/{$ref->proveedor_id}/saldo")->assertJsonPath('saldo', '0.00');
});

it('S-79: pago parcial, anulación y nuevo pago: el pendiente se recalcula y la factura vuelve a la lista', function () {
    $ref = referencia('RN-4410');
    $factura = compra($ref, 1, 500, aCredito());
    actuarComo(Rol::Admin);

    $primero = pagar($ref->proveedor_id, 500, ['ingreso_id' => $factura->id])->assertCreated()->json('id');
    expect(s79Documento($ref->proveedor_id, $factura->id))->toBeNull();

    $this->postJson("/api/pagos/{$primero}/anulaciones", ['motivo' => 'Transferencia rechazada'])->assertCreated();
    expect(s79Documento($ref->proveedor_id, $factura->id))->toMatchArray(['pendiente' => '500.00', 'abonado' => '0.00']);

    pagar($ref->proveedor_id, 120, ['ingreso_id' => $factura->id])->assertCreated();
    expect(s79Documento($ref->proveedor_id, $factura->id))->toMatchArray(['pendiente' => '380.00', 'abonado' => '120.00']);
});

it('S-79: el pago valida el pendiente con el proveedor y la factura bloqueados (SELECT … FOR UPDATE)', function () {
    $ref = referencia('RN-4410');
    $factura = compra($ref, 1, 500, aCredito());
    actuarComo(Rol::Contable);

    DB::enableQueryLog();
    pagar($ref->proveedor_id, 100, ['ingreso_id' => $factura->id])->assertCreated();
    $consultas = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $bloqueos = $consultas->filter(fn ($q) => str_contains($q, 'for update'))->values();
    $ingresos = $bloqueos->search(fn ($q) => str_contains($q, '`ingresos`'));
    $insercion = $consultas->search(fn ($q) => str_starts_with($q, 'insert into `pagos_proveedores`'));
    expect($bloqueos->contains(fn ($q) => str_contains($q, '`proveedores`')))->toBeTrue()
        ->and($ingresos)->not->toBeFalse()
        // El bloqueo de la factura va antes de insertar: el pendiente se lee ya bloqueado.
        ->and($consultas->search($bloqueos[$ingresos]))->toBeLessThan($insercion);
});

it('S-79: dos pagos que juntos superan el pendiente: el segundo ve lo que dejó el primero y se rechaza', function () {
    $ref = referencia('RN-4410');
    $factura = compra($ref, 1, 500, aCredito());
    actuarComo(Rol::Contable);

    // Serializados por el bloqueo, el segundo lee el pendiente ya rebajado (no el de antes del primero).
    pagar($ref->proveedor_id, 300, ['ingreso_id' => $factura->id])->assertCreated();
    pagar($ref->proveedor_id, 300, ['ingreso_id' => $factura->id])->assertUnprocessable()->assertJsonPath('pendiente', '200.00');

    expect((string) PagoProveedor::where('ingreso_id', $factura->id)->sum('valor'))->toBe('300.00');
});

/* ───────────── Abonos de clientes ───────────── */

it('S-79: un abono sin venta responde 422 y no crea nada', function () {
    $cliente = Cliente::factory()->create();
    ventaCredito($cliente, [100]);
    actuarComo(Rol::Mostrador);

    abonar($cliente, 40, ['venta_id' => null])->assertUnprocessable()
        ->assertJsonValidationErrors(['venta_id' => 'Elija la factura o remisión a la que se aplica el abono.']);
    expect(AbonoCliente::count())->toBe(0);
});

it('S-79: abono a una venta de otro cliente, abierta, mayor al pendiente o anterior al cierre: 422', function () {
    $cliente = Cliente::factory()->create();
    [$venta] = ventaCredito($cliente, [100]);
    [$ajena] = ventaCredito(Cliente::factory()->create(), [50]);
    [$abierta] = ventaCredito($cliente, [30], cerrar: false);
    fecharVenta($venta, now('America/Bogota')->subDays(3)->toDateString(), now('America/Bogota')->addDays(27)->toDateString());
    actuarComo(Rol::Mostrador);

    abonar($cliente, 10, ['venta_id' => $ajena])->assertUnprocessable()->assertJsonPath('campo', 'venta_id');
    abonar($cliente, 10, ['venta_id' => $abierta])->assertUnprocessable()->assertJsonPath('campo', 'venta_id');
    abonar($cliente, 101, ['venta_id' => $venta])->assertUnprocessable()->assertJsonPath('campo', 'valor')->assertJsonPath('pendiente', '100.00');
    abonar($cliente, 10, ['venta_id' => $venta, 'fecha' => now('America/Bogota')->subDays(4)->toDateString()])
        ->assertUnprocessable()->assertJsonPath('campo', 'fecha');
    abonar($cliente, 10.555, ['venta_id' => $venta])->assertUnprocessable()->assertJsonValidationErrors('valor');

    expect(AbonoCliente::count())->toBe(0);
});

it('S-79 / S-73: abonos parciales sucesivos a una venta de solo valor hasta cancelarla', function () {
    $cliente = Cliente::factory()->create();
    $venta = s79VentaSoloValor($cliente, 300000);
    actuarComo(Rol::Mostrador);

    abonar($cliente, 100000.50, ['venta_id' => $venta])->assertCreated()->assertJsonPath('resumen.saldo', '199999.50');
    abonar($cliente, 199999.50, ['venta_id' => $venta])->assertCreated()->assertJsonPath('resumen.saldo', '0.00');
    abonar($cliente, 1, ['venta_id' => $venta])->assertUnprocessable()->assertJsonPath('message', "La venta #{$venta} no tiene saldo pendiente.");

    $fila = collect($this->getJson("/api/clientes/{$cliente->id}/cartera")->json('ventas'))->firstWhere('id', $venta);
    expect($fila)->toMatchArray(['total' => '300000.00', 'aplicado' => '300000.00', 'pendiente' => '0.00']);
});

it('S-79: un abono a una venta se valida con la venta bloqueada (SELECT … FOR UPDATE)', function () {
    $cliente = Cliente::factory()->create();
    [$venta] = ventaCredito($cliente, [100]);
    actuarComo(Rol::Mostrador);

    DB::enableQueryLog();
    abonar($cliente, 40, ['venta_id' => $venta])->assertCreated();
    $consultas = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $bloqueo = $consultas->search(fn ($q) => str_contains($q, 'from `ventas`') && str_contains($q, 'for update'));
    $insercion = $consultas->search(fn ($q) => str_starts_with($q, 'insert into `abonos_clientes`'));
    expect($bloqueo)->not->toBeFalse()->and($bloqueo)->toBeLessThan($insercion);
});

it('S-79 / RF-30: vencimientos por cobrar descuenta el abono de su venta, igual que el detalle de la cartera', function () {
    config(['cartera.aviso_dias' => 60]);
    $cliente = Cliente::factory()->create();
    $hoy = Carbon::now('America/Bogota')->startOfDay();
    [$vieja] = ventaCredito($cliente, [60]);
    [$nueva] = ventaCredito($cliente, [50]);
    fecharVenta($vieja, $hoy->copy()->subDays(40)->toDateString(), $hoy->copy()->subDays(10)->toDateString());
    fecharVenta($nueva, $hoy->copy()->subDays(10)->toDateString(), $hoy->copy()->addDays(20)->toDateString());
    actuarComo(Rol::Mostrador);
    abonar($cliente, 30, ['venta_id' => $nueva])->assertCreated();

    actuarComo(Rol::Contable);
    $filas = collect($this->getJson('/api/vencimientos?tipo=por_cobrar')->assertOk()->json('data'))->keyBy('documento_id');
    expect($filas[$vieja])->toMatchArray(['pendiente' => '60.00', 'abonado' => '0.00'])
        ->and($filas[$nueva])->toMatchArray(['pendiente' => '20.00', 'abonado' => '30.00']);
});

/* ───────────── Datos anteriores a S-79 ───────────── */

it('S-79: pagos y abonos antiguos sin documento se siguen leyendo, sumando y anulando; el saldo no cambia', function () {
    Carbon::setTestNow('2026-11-21 10:00:00');
    $ref = referencia('RN-4410');
    compra($ref, 1, 500, aCredito('2026-12-31'));
    $cliente = Cliente::factory()->create();
    ventaCredito($cliente, [100]);

    $pago = pagoHistorico($ref->proveedor_id, 200);
    $abono = abonoHistorico($cliente, 150); // deja 50 a favor
    actuarComo(Rol::Admin);

    $this->getJson("/api/proveedores/{$ref->proveedor_id}/saldo")->assertJsonPath('saldo', '300.00')->assertJsonPath('documentos.0.abonado', '200.00');
    expect(app(CarteraService::class)->resumenDe($cliente))->toMatchArray(['saldo' => '-50.00', 'saldo_a_favor' => true]);

    $this->postJson("/api/pagos/{$pago->id}/anulaciones", ['motivo' => 'Registro antiguo duplicado'])->assertCreated();
    $this->postJson("/api/abonos/{$abono->id}/anulaciones", ['motivo' => 'Registro antiguo duplicado'])->assertCreated()
        ->assertJsonPath('resumen.saldo', '100.00');
    $this->getJson("/api/proveedores/{$ref->proveedor_id}/saldo")->assertJsonPath('saldo', '500.00');
});

/* ───────────── Permisos (sin cambios) ───────────── */

it('S-79: contable paga y no anula; admin anula; mostrador abona y no anula; logística 403', function () {
    $ref = referencia('RN-4410');
    $factura = compra($ref, 1, 500, aCredito());
    $cliente = Cliente::factory()->create();
    [$venta] = ventaCredito($cliente, [100]);

    actuarComo(Rol::Contable);
    $pago = pagar($ref->proveedor_id, 100, ['ingreso_id' => $factura->id])->assertCreated()->json('id');
    $this->postJson("/api/pagos/{$pago}/anulaciones", ['motivo' => 'Error'])->assertForbidden();
    abonar($cliente, 10, ['venta_id' => $venta])->assertForbidden();

    actuarComo(Rol::Mostrador);
    $abono = abonar($cliente, 10, ['venta_id' => $venta])->assertCreated()->json('abono.id');
    $this->postJson("/api/abonos/{$abono}/anulaciones", ['motivo' => 'Error'])->assertForbidden();
    pagar($ref->proveedor_id, 10, ['ingreso_id' => $factura->id])->assertForbidden();

    actuarComo(Rol::Logistica);
    pagar($ref->proveedor_id, 10, ['ingreso_id' => $factura->id])->assertForbidden();
    abonar($cliente, 10, ['venta_id' => $venta])->assertForbidden();

    actuarComo(Rol::Admin);
    $this->postJson("/api/pagos/{$pago}/anulaciones", ['motivo' => 'Error'])->assertCreated();
    $this->postJson("/api/abonos/{$abono}/anulaciones", ['motivo' => 'Error'])->assertCreated();
});
