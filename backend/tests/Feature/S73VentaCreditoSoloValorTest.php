<?php

use App\Enums\Rol;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\MovimientoInventario;
use App\Models\Reverso;
use App\Models\Venta;
use App\Services\CarteraService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/*
 * RF-14 cambiado (S-73, S-74): la venta a crédito es un registro de cartera SIN ítems: cliente +
 * factura o remisión + valor total con IVA. No baja inventario ni entra a caja; cuenta en saldos,
 * informes, panel y meta del día. Se anula completa con motivo.
 */

beforeEach(function () {
    sembrarDemo();
    Carbon::setTestNow('2026-11-20 15:00:00'); // 10:00 en Bogotá
});

afterEach(fn () => Carbon::setTestNow());

function svRegistrar(Cliente $cliente, array $extra = [])
{
    return test()->postJson('/api/ventas/credito', ['cliente_id' => $cliente->id, 'tipo_documento' => 'factura', 'valor_total' => 500000, ...$extra]);
}

/** Registra como mostrador y vuelve a la sesión anterior. Devuelve el id. */
function svComoMostrador(Cliente $cliente, array $extra = []): int
{
    $anterior = auth()->user();
    actuarComo(Rol::Mostrador);
    $id = svRegistrar($cliente, $extra)->assertCreated()->json('id');
    $anterior ? test()->actingAs($anterior) : null;

    return $id;
}

function svAnular(int $ventaId, string $motivo = 'Se registró por error')
{
    return test()->postJson("/api/ventas/{$ventaId}/reversos", ['tipo' => 'anulacion', 'motivo' => $motivo]);
}

it('S-73: el mostrador registra una venta a crédito de solo valor: cerrada, con vencimiento, sin inventario ni caja', function () {
    $cliente = Cliente::factory()->create(['plazo_dias' => 30]);
    $mostrador = actuarComo(Rol::Mostrador);
    $movimientos = MovimientoInventario::count();

    $r = svRegistrar($cliente, ['numero_documento' => ' FE-120 '])->assertCreated()
        ->assertJsonPath('estado', 'cerrada')
        ->assertJsonPath('forma_pago', 'credito')
        ->assertJsonPath('solo_valor', true)
        ->assertJsonPath('tipo_documento', 'factura')
        ->assertJsonPath('documento', 'Factura N.º FE-120')
        ->assertJsonPath('factura_contapyme', 'FE-120')
        ->assertJsonPath('valor_total', '500000.00')
        ->assertJsonPath('vence_el', '2026-12-20')
        ->assertJsonPath('usuario.id', $mostrador->id)
        ->assertJsonPath('items', []);

    expect(MovimientoInventario::count())->toBe($movimientos)
        ->and(Venta::find($r->json('id'))->pagos()->count())->toBe(0);
    $fila = Auditoria::where('tabla', 'ventas')->where('registro_id', $r->json('id'))->sole();
    expect($fila->campo)->toBe('*')->and($fila->usuario_id)->toBe($mostrador->id)
        ->and(json_decode($fila->valor_nuevo, true)['valor_total'])->toBe('500000.00');

    // Sin número: "Factura sin número". Remisión con decimales.
    svRegistrar($cliente, ['tipo_documento' => 'remision', 'valor_total' => '1234.56'])->assertCreated()
        ->assertJsonPath('documento', 'Remisión sin número')
        ->assertJsonPath('valor_total', '1234.56');
});

it('S-73: valida cliente activo, tipo y valor mayor que 0 con máximo 2 decimales', function () {
    $cliente = Cliente::factory()->create();
    $inactivo = Cliente::factory()->create(['activo' => false]);
    actuarComo(Rol::Mostrador);

    svRegistrar($cliente, ['valor_total' => 0])->assertUnprocessable()->assertJsonValidationErrors('valor_total');
    svRegistrar($cliente, ['valor_total' => -5])->assertUnprocessable()->assertJsonValidationErrors('valor_total');
    svRegistrar($cliente, ['valor_total' => '10.123'])->assertUnprocessable()->assertJsonValidationErrors('valor_total');
    svRegistrar($cliente, ['tipo_documento' => 'recibo'])->assertUnprocessable()->assertJsonValidationErrors('tipo_documento');
    svRegistrar($cliente, ['tipo_documento' => null])->assertUnprocessable()->assertJsonValidationErrors('tipo_documento');
    svRegistrar($inactivo)->assertUnprocessable()->assertJsonValidationErrors('cliente_id');
    $this->postJson('/api/ventas/credito', ['tipo_documento' => 'factura', 'valor_total' => 10])->assertUnprocessable()->assertJsonValidationErrors('cliente_id');
    expect(Venta::whereNotNull('valor_total')->count())->toBe(0);
});

it('S-73: solo el mostrador registra (403 para admin, logística y contable)', function (Rol $rol) {
    $cliente = Cliente::factory()->create();
    actuarComo($rol);
    svRegistrar($cliente)->assertForbidden();
})->with([Rol::Admin, Rol::Logistica, Rol::Contable]);

it('S-73: el mismo cliente + tipo + número vigente se rechaza; se permite si el anterior está anulado', function () {
    $cliente = Cliente::factory()->create();
    $otro = Cliente::factory()->create();
    actuarComo(Rol::Mostrador);

    $id = svRegistrar($cliente, ['numero_documento' => '77'])->assertCreated()->json('id');
    svRegistrar($cliente, ['numero_documento' => '77'])->assertUnprocessable()->assertJsonValidationErrors('numero_documento');
    svRegistrar($cliente, ['numero_documento' => '77', 'tipo_documento' => 'remision'])->assertCreated();
    svRegistrar($otro, ['numero_documento' => '77'])->assertCreated();
    // Sin número no hay control de duplicado.
    svRegistrar($cliente)->assertCreated();
    svRegistrar($cliente)->assertCreated();

    svAnular($id)->assertOk();
    svRegistrar($cliente, ['numero_documento' => '77'])->assertCreated();
});

it('S-74: el saldo suma el valor, baja con los abonos y con la anulación; la antigüedad usa vence_el', function () {
    $cliente = Cliente::factory()->create(['plazo_dias' => 30]);
    $id = svComoMostrador($cliente, ['valor_total' => 500000]);
    $cartera = app(CarteraService::class);

    expect($cartera->saldoDe($cliente))->toBe('500000.00');

    actuarComo(Rol::Mostrador);
    abonar($cliente, 200000)->assertCreated();
    expect($cartera->saldoDe($cliente->fresh()))->toBe('300000.00');

    // Antigüedad por vence_el: con el vencimiento movido 40 días atrás queda en 31-60.
    fecharVenta($id, '2026-09-11', '2026-10-11');
    $antiguedad = $cartera->saldoCliente($cliente, Carbon::parse('2026-11-20'));
    expect($antiguedad->resumen()['saldo'])->toBe('300000.00')
        ->and($antiguedad->resumen()['vencido'])->toBe('300000.00')
        ->and($antiguedad->resumen()['rangos']['d31_60'])->toBe('300000.00');

    actuarComo(Rol::Admin);
    $detalle = $this->getJson("/api/clientes/{$cliente->id}/cartera")->assertOk();
    expect(collect($detalle->json('ventas'))->firstWhere('id', $id)['documento'])->toBe('Factura sin número');
    $estado = collect($this->getJson("/api/clientes/{$cliente->id}/estado-cuenta")->assertOk()->json('movimientos'));
    expect($estado->firstWhere('venta_id', $id)['descripcion'])->toContain('Factura sin número');

    svAnular($id)->assertOk();
    // Anulada: no hay deuda; el abono queda como saldo a favor (como con cualquier venta reversada).
    expect($cartera->saldoDe($cliente->fresh()))->toBe('-200000.00');
});

it('S-73: la anulación crea un reverso con usuario, motivo y monto; la venta no se borra ni se anula dos veces', function () {
    $cliente = Cliente::factory()->create();
    $id = svComoMostrador($cliente, ['valor_total' => 123456.78]);
    $admin = actuarComo(Rol::Admin); // S-43: el admin reversa con motivo

    svAnular($id, '')->assertUnprocessable()->assertJsonValidationErrors('motivo');
    $this->postJson("/api/ventas/{$id}/reversos", ['tipo' => 'devolucion', 'motivo' => 'Devolvió todo'])->assertUnprocessable();
    svAnular($id)->assertOk()->assertJsonPath('anulada', true)->assertJsonPath('reversos.0.valor', '123456.78');

    $reverso = Reverso::where('venta_id', $id)->sole();
    expect($reverso->usuario_id)->toBe($admin->id)
        ->and($reverso->motivo)->toBe('Se registró por error')
        ->and($reverso->valor)->toBe('123456.78')
        ->and($reverso->items()->count())->toBe(0)
        ->and(Venta::find($id))->not->toBeNull();
    svAnular($id)->assertUnprocessable();

    // Logística no anula ventas.
    actuarComo(Rol::Logistica);
    svAnular($id)->assertForbidden();
});

it('S-74: informe de ventas a crédito: total incluye el registro, base e IVA nulos y sus totales sin contaminar', function () {
    $cliente = Cliente::factory()->create();
    ventaCreditoCerrada($cliente, 1, 119); // base 100, IVA 19
    svComoMostrador($cliente, ['valor_total' => 1000, 'tipo_documento' => 'remision', 'numero_documento' => 'R-9']);
    $anulada = svComoMostrador($cliente, ['valor_total' => 5000]);
    actuarComo(Rol::Admin);
    svAnular($anulada)->assertOk();

    $r = $this->getJson('/api/reportes/ventas-credito')->assertOk();
    $fila = collect($r->json('data'))->firstWhere('total', '1000.00');
    expect($r->json('total'))->toBe(2)
        ->and($fila['documento'])->toBe('Remisión N.º R-9')
        ->and($fila['base'])->toBeNull()
        ->and($fila['iva'])->toBeNull()
        ->and($r->json('totales.base'))->toBe('100.00')
        ->and($r->json('totales.iva'))->toBe('19.00')
        ->and($r->json('totales.total'))->toBe('1119.00');
});

it('S-74: panel: el valor cuenta como venta a crédito; unidades y margen no cambian', function () {
    $cliente = Cliente::factory()->create();
    ventaCreditoCerrada($cliente, 1, 119);
    actuarComo(Rol::Admin);
    $antes = $this->getJson('/api/panel/resumen?refrescar=1&desde=2026-11-20&hasta=2026-11-20')->assertOk()->json();

    svComoMostrador($cliente, ['valor_total' => 1000]);
    $r = $this->getJson('/api/panel/resumen?refrescar=1&desde=2026-11-20&hasta=2026-11-20')->assertOk()->json();

    expect($r['ventas']['total'])->toBe(bcadd($antes['ventas']['total'], '1000', 2))
        ->and($r['ventas']['credito'])->toBe(bcadd($antes['ventas']['credito'], '1000', 2))
        ->and($r['ventas']['ventas'])->toBe($antes['ventas']['ventas'] + 1)
        ->and($r['ventas']['ventas_credito'])->toBe($antes['ventas']['ventas_credito'] + 1)
        ->and($r['ventas']['unidades'])->toBe($antes['ventas']['unidades'])
        ->and($r['ventas']['base'])->toBe($antes['ventas']['base'])
        ->and($r['ventas']['iva_incluido'])->toBe($antes['ventas']['iva_incluido'])
        ->and($r['margen']['margen'])->toBe($antes['margen']['margen']);

    $dia = collect($this->getJson('/api/panel/ventas-por-dia?refrescar=1&desde=2026-11-20&hasta=2026-11-20')->assertOk()->json('serie'))->sole();
    expect($dia['credito'])->toBe(bcadd($antes['ventas']['credito'], '1000', 2))->and($dia['unidades'])->toBe($antes['ventas']['unidades']);
});

it('S-74: caja del día: cuenta como venta a crédito (meta) y no entra a ninguna cuenta de pago', function () {
    $cliente = Cliente::factory()->create();
    actuarComo(Rol::Admin);
    $antes = $this->getJson('/api/caja/dias/2026-11-20')->assertOk()->json('resumen');

    svComoMostrador($cliente, ['valor_total' => 1000]);
    $r = $this->getJson('/api/caja/dias/2026-11-20')->assertOk()->json('resumen');

    expect($r['ventas_credito'])->toBe(bcadd($antes['ventas_credito'], '1000', 2))
        ->and($r['total_ingreso_dia'])->toBe(bcadd($antes['total_ingreso_dia'], '1000', 2))
        ->and($r['efectivo_neto_dia'])->toBe($antes['efectivo_neto_dia'])
        ->and(collect($r['cuentas'])->sum(fn ($c) => (float) $c['ventas']))->toBe(collect($antes['cuentas'])->sum(fn ($c) => (float) $c['ventas']));
});

it('S-73: con la caja de hoy cerrada no se registran ventas a crédito', function () {
    $cliente = Cliente::factory()->create();
    actuarComo(Rol::Mostrador);
    $this->putJson('/api/caja/dias/2026-11-20/apertura', ['valor' => 0, 'motivo' => 'Apertura de prueba'])->assertSuccessful();
    $this->postJson('/api/caja/cierres', ['fecha' => '2026-11-20', 'efectivo_contado' => 0])->assertCreated();

    svRegistrar($cliente)->assertUnprocessable()->assertJsonPath('caja_cerrada', '2026-11-20');
});

it('S-74: regresión: las ventas a crédito con ítems dan los mismos números con un registro de solo valor al lado', function () {
    $conItems = Cliente::factory()->create();
    $venta = ventaCreditoCerrada($conItems, 2, 119);
    $cartera = app(CarteraService::class);
    actuarComo(Rol::Admin);
    $saldoAntes = $cartera->saldoDe($conItems);
    $informeAntes = collect($this->getJson('/api/reportes/ventas-credito')->json('data'))->firstWhere('id', $venta->id);
    $estadoAntes = $this->getJson("/api/clientes/{$conItems->id}/estado-cuenta")->json('saldo_final');

    svComoMostrador(Cliente::factory()->create(), ['valor_total' => 999]);
    svComoMostrador($conItems, ['valor_total' => 1, 'tipo_documento' => 'remision']);
    actuarComo(Rol::Admin);

    expect(collect($this->getJson('/api/reportes/ventas-credito')->json('data'))->firstWhere('id', $venta->id))->toBe($informeAntes)
        ->and($cartera->saldoDe($conItems->fresh()))->toBe(bcadd($saldoAntes, '1', 2))
        ->and($this->getJson("/api/ventas/{$venta->id}")->json('solo_valor'))->toBeFalse();
    expect($estadoAntes)->not->toBeNull();
});

it('S-73: no hay rutas DELETE ni de edición para ventas', function () {
    $rutas = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'api/ventas') && array_intersect(['DELETE', 'PUT'], $r->methods()));
    expect($rutas)->toBeEmpty();
});
