<?php

use App\Enums\Rol;
use App\Exceptions\RegistroInmutableException;
use App\Models\AbonoAnulacion;
use App\Models\AbonoCliente;
use App\Models\Cliente;
use App\Models\Venta;
use Database\Seeders\CarteraDemoSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/*
 * RF-15 (adenda v1.2, paso 4; S-09, S-12, S-13, S-30): abonos de clientes, saldo y antigüedad.
 */

beforeEach(fn () => sembrarDemo());

function filaCartera(Cliente $cliente, array $filtros = []): ?array
{
    return collect(test()->getJson('/api/cartera?'.http_build_query(['solo_con_saldo' => 0, ...$filtros]))->assertOk()->json('data'))
        ->firstWhere('cliente.id', $cliente->id);
}

it('RF-15 (a) / criterio 1: venta a crédito de 100 y abono de 40 dejan saldo 60', function () {
    $cliente = Cliente::factory()->create();
    ventaCredito($cliente, [100]);

    actuarComo(Rol::Mostrador);
    abonar($cliente, 40, ['medio' => 'transferencia', 'referencia' => 'TR-991'])->assertCreated()
        ->assertJsonPath('abono.valor', '40.00')
        ->assertJsonPath('abono.medio', 'transferencia')
        ->assertJsonPath('abono.anulado', false)
        ->assertJsonPath('resumen.saldo', '60.00');

    expect(filaCartera($cliente))->toMatchArray(['ventas' => '100.00', 'abonado' => '40.00', 'saldo' => '60.00', 'saldo_a_favor' => false]);
});

it('RF-15 (b): reversar un ítem de la venta baja el saldo en su valor', function () {
    $cliente = Cliente::factory()->create();
    [$ventaId, $items] = ventaCredito($cliente, [100, 50]);

    actuarComo(Rol::Mostrador);
    expect(filaCartera($cliente)['saldo'])->toBe('150.00');

    $this->postJson("/api/ventas/{$ventaId}/reversos", ['tipo' => 'devolucion', 'motivo' => 'Devolvió una pieza', 'venta_item_id' => $items[1]])->assertOk();
    expect(filaCartera($cliente)['saldo'])->toBe('100.00');
});

it('RF-15 (c): anular el abono devuelve el saldo a 100 y el abono sigue visible como anulado', function () {
    $cliente = Cliente::factory()->create();
    ventaCredito($cliente, [100]);
    $admin = actuarComo(Rol::Admin);
    $abonoId = abonar($cliente, 40)->json('abono.id');

    $this->postJson("/api/abonos/{$abonoId}/anulaciones", ['motivo' => 'Se registró dos veces'])->assertCreated()
        ->assertJsonPath('abono.anulado', true)
        ->assertJsonPath('abono.anulacion.motivo', 'Se registró dos veces')
        ->assertJsonPath('abono.anulacion.usuario.id', $admin->id)
        ->assertJsonPath('resumen.saldo', '100.00');

    expect(AbonoCliente::find($abonoId))->not->toBeNull()
        ->and(filaCartera($cliente)['saldo'])->toBe('100.00');
});

it('RF-15 (d) / S-09: una venta a crédito abierta no cuenta; al cerrarla sí', function () {
    $cliente = Cliente::factory()->create();
    [$ventaId] = ventaCredito($cliente, [100], cerrar: false);

    actuarComo(Rol::Mostrador);
    expect(filaCartera($cliente))->toBeNull();

    $this->postJson("/api/ventas/{$ventaId}/cerrar")->assertOk();
    expect(filaCartera($cliente)['saldo'])->toBe('100.00');
});

it('RF-15 (e): una venta de contado no cuenta en la cartera', function () {
    $cliente = Cliente::factory()->create();
    actuarComo(Rol::Mostrador);
    $ventaId = $this->postJson('/api/ventas')->json('id');
    $this->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => unidadDe('RN-4410')->codigo_barras, 'metodo_captura' => 'escaneado'])->assertCreated();
    $this->postJson("/api/ventas/{$ventaId}/cerrar")->assertOk();

    expect(filaCartera($cliente))->toBeNull()
        ->and($this->getJson('/api/cartera?solo_con_saldo=0')->json('totales.cartera_total'))->toBe('0.00');
});

it('RF-15 (f) / criterio 8: un abono mayor al saldo deja saldo negativo, mostrado como saldo a favor', function () {
    $cliente = Cliente::factory()->create();
    ventaCredito($cliente, [100]);

    actuarComo(Rol::Mostrador);
    abonar($cliente, 130)->assertCreated()->assertJsonPath('resumen.saldo', '-30.00')->assertJsonPath('resumen.saldo_a_favor', true);

    $this->getJson('/api/cartera?solo_con_saldo=1')->assertOk()->assertJsonCount(0, 'data');
    expect(filaCartera($cliente))->toMatchArray(['saldo' => '-30.00', 'saldo_a_favor' => true])
        ->and($this->getJson('/api/cartera?solo_con_saldo=0')->json('totales.saldo_a_favor'))->toBe('30.00');
});

it('RF-15 (g) / S-12 / S-13: FIFO salda la venta vencida primero; vencido 0 y el resto al día', function () {
    $cliente = Cliente::factory()->create();
    $hoy = Carbon::now('America/Bogota')->startOfDay();
    [$vieja] = ventaCredito($cliente, [60]);
    [$nueva] = ventaCredito($cliente, [50]);
    fecharVenta($vieja, $hoy->copy()->subDays(70)->toDateString(), $hoy->copy()->subDays(40)->toDateString());
    fecharVenta($nueva, $hoy->copy()->subDays(5)->toDateString(), $hoy->copy()->addDays(25)->toDateString());

    actuarComo(Rol::Mostrador);
    // Antes del abono: 60 vencida hace 40 días (31-60) y 50 al día.
    expect(filaCartera($cliente))->toMatchArray([
        'saldo' => '110.00', 'vencido' => '60.00',
        'antiguedad' => ['al_dia' => '50.00', 'd1_30' => '0.00', 'd31_60' => '60.00', 'd61_90' => '0.00', 'mas_90' => '0.00'],
    ]);

    abonar($cliente, 70)->assertCreated();

    expect(filaCartera($cliente))->toMatchArray([
        'saldo' => '40.00', 'vencido' => '0.00', 'proximo_vencimiento' => $hoy->copy()->addDays(25)->toDateString(),
        'antiguedad' => ['al_dia' => '40.00', 'd1_30' => '0.00', 'd31_60' => '0.00', 'd61_90' => '0.00', 'mas_90' => '0.00'],
    ]);
});

it('RF-15 / S-13: la fecha de corte ubica los rangos y deja fuera lo posterior', function () {
    $cliente = Cliente::factory()->create();
    $hoy = Carbon::now('America/Bogota')->startOfDay();
    [$venta] = ventaCredito($cliente, [80]);
    fecharVenta($venta, $hoy->copy()->subDays(120)->toDateString(), $hoy->copy()->subDays(95)->toDateString());

    actuarComo(Rol::Mostrador);
    expect(filaCartera($cliente)['antiguedad']['mas_90'])->toBe('80.00');
    // A un corte de hace 20 días estaba en 61-90.
    expect(filaCartera($cliente, ['corte' => $hoy->copy()->subDays(20)->toDateString()])['antiguedad']['d61_90'])->toBe('80.00');
    // Antes de cerrarse la venta no había cartera.
    expect(filaCartera($cliente, ['corte' => $hoy->copy()->subDays(121)->toDateString()]))->toBeNull();
});

it('RF-15 (h): valor 0 o negativo, fecha futura, cliente inactivo y anular dos veces responden 422', function () {
    $cliente = Cliente::factory()->create();
    ventaCredito($cliente, [100]);
    actuarComo(Rol::Admin);

    abonar($cliente, 0)->assertUnprocessable()->assertJsonValidationErrors('valor');
    abonar($cliente, -5)->assertUnprocessable()->assertJsonValidationErrors('valor');
    abonar($cliente, 10, ['fecha' => now('America/Bogota')->addDay()->toDateString()])
        ->assertUnprocessable()->assertJsonPath('message', 'La fecha del abono no puede ser futura.');
    abonar($cliente, 10, ['medio' => 'cheque'])->assertUnprocessable()->assertJsonValidationErrors('medio');

    $inactivo = Cliente::factory()->inactivo()->create();
    abonar($inactivo, 10)->assertUnprocessable();

    $abonoId = abonar($cliente, 10)->assertCreated()->json('abono.id');
    $this->postJson("/api/abonos/{$abonoId}/anulaciones", ['motivo' => ''])->assertUnprocessable()->assertJsonValidationErrors('motivo');
    $this->postJson("/api/abonos/{$abonoId}/anulaciones", ['motivo' => 'Error'])->assertCreated();
    $this->postJson("/api/abonos/{$abonoId}/anulaciones", ['motivo' => 'Otra vez'])->assertUnprocessable()->assertJsonPath('message', 'Este abono ya fue anulado.');

    expect(AbonoCliente::count())->toBe(1)->and(AbonoAnulacion::count())->toBe(1);
});

it('RF-15 (i) / criterio 7: mostrador y admin registran abonos; solo admin anula; logística 403 en todo', function () {
    $cliente = Cliente::factory()->create();
    ventaCredito($cliente, [100]);

    actuarComo(Rol::Mostrador);
    $abonoId = abonar($cliente, 10)->assertCreated()->json('abono.id');
    $this->getJson('/api/cartera')->assertOk();
    $this->postJson("/api/abonos/{$abonoId}/anulaciones", ['motivo' => 'No'])->assertForbidden();

    actuarComo(Rol::Logistica);
    $this->getJson('/api/cartera')->assertForbidden();
    abonar($cliente, 10)->assertForbidden();
    $this->postJson("/api/abonos/{$abonoId}/anulaciones", ['motivo' => 'No'])->assertForbidden();

    actuarComo(Rol::Admin);
    abonar($cliente, 10)->assertCreated();
    $this->postJson("/api/abonos/{$abonoId}/anulaciones", ['motivo' => 'Error de digitación'])->assertCreated();

    expect(AbonoCliente::count())->toBe(2)->and(AbonoAnulacion::count())->toBe(1);
});

it('RF-15 (j) / criterio 9: abonos y anulaciones son inmutables y no tienen rutas DELETE ni PUT', function () {
    $cliente = Cliente::factory()->create();
    ventaCredito($cliente, [100]);
    actuarComo(Rol::Admin);
    $abonoId = abonar($cliente, 40)->json('abono.id');
    $this->postJson("/api/abonos/{$abonoId}/anulaciones", ['motivo' => 'Error'])->assertCreated();

    $abono = AbonoCliente::findOrFail($abonoId);
    expect(fn () => $abono->update(['valor' => 1]))->toThrow(RegistroInmutableException::class)
        ->and(fn () => $abono->delete())->toThrow(RegistroInmutableException::class)
        ->and(fn () => AbonoAnulacion::firstOrFail()->delete())->toThrow(RegistroInmutableException::class);

    $rutas = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_contains($r->uri(), 'abono') && array_intersect(['DELETE', 'PUT', 'PATCH'], $r->methods()));
    expect($rutas)->toBeEmpty();

    $this->deleteJson("/api/abonos/{$abonoId}")->assertNotFound();
    $this->putJson("/api/abonos/{$abonoId}", ['valor' => 1])->assertNotFound();
    // Un abono no toca existencias ni ventas.
    expect(Venta::count())->toBe(1);
});

it('RF-15 / criterio 5: la lista busca por nombre o documento, filtra solo con saldo y ordena por mayor saldo', function () {
    $ana = Cliente::factory()->create(['nombre' => 'Ana Taller', 'documento' => '800100']);
    $beto = Cliente::factory()->create(['nombre' => 'Beto Motos', 'documento' => '900200']);
    $carla = Cliente::factory()->create(['nombre' => 'Carla Repuestos', 'documento' => '700300']);
    ventaCredito($ana, [50]);
    ventaCredito($beto, [200]);
    ventaCredito($carla, [30]);

    actuarComo(Rol::Mostrador);
    abonar($carla, 30)->assertCreated(); // saldo 0

    $this->getJson('/api/cartera')->assertOk() // solo_con_saldo por defecto en la pantalla; aquí sin filtro
        ->assertJsonPath('data.0.cliente.nombre', 'Beto Motos')
        ->assertJsonPath('totales.cartera_total', '250.00')
        ->assertJsonPath('totales.clientes_con_saldo', 2);
    $this->getJson('/api/cartera?solo_con_saldo=1')->assertJsonCount(2, 'data');
    $this->getJson('/api/cartera?busqueda=Ana')->assertJsonCount(1, 'data')->assertJsonPath('data.0.cliente.id', $ana->id);
    $this->getJson('/api/cartera?busqueda=9002')->assertJsonCount(1, 'data')->assertJsonPath('data.0.cliente.id', $beto->id);
    $this->getJson('/api/cartera?busqueda=Taller')->assertJsonCount(0, 'data'); // sin comodín inicial
});

it('RF-15: CarteraDemoSeeder deja tres clientes de ejemplo (vencido saldado por FIFO, saldo a favor y vencida)', function () {
    $this->seed(CarteraDemoSeeder::class);
    actuarComo(Rol::Admin);

    $filas = collect($this->getJson('/api/cartera?solo_con_saldo=0')->assertOk()->json('data'))->keyBy('cliente.nombre');
    expect($filas->keys()->sort()->values()->all())->toBe(['Motos y Repuestos Juan', 'Taller El Pistón', 'Transportes La Flota'])
        ->and($filas['Taller El Pistón'])->toMatchArray(['saldo' => '40000.00', 'vencido' => '0.00'])
        ->and($filas['Motos y Repuestos Juan'])->toMatchArray(['saldo' => '-5000.00', 'saldo_a_favor' => true])
        ->and($filas['Transportes La Flota'])->toMatchArray(['saldo' => '200000.00', 'vencido' => '200000.00']);
});
