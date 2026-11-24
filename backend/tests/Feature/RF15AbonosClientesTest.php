<?php

use App\Enums\FormaPago;
use App\Enums\MetodoCaptura;
use App\Enums\Rol;
use App\Enums\TipoReverso;
use App\Exceptions\RegistroInmutableException;
use App\Models\AbonoCliente;
use App\Models\Cliente;
use App\Services\ReversoService;
use App\Services\VentaService;
use Illuminate\Support\Carbon;

beforeEach(fn () => sembrarDemo());

function abonarId(Cliente $cliente, int $valor, array $extra = []): int
{
    return test()->postJson("/api/clientes/{$cliente->id}/abonos", ['valor' => $valor, 'medio' => 'efectivo', ...$extra])
        ->assertCreated()
        ->json('id');
}

it('RF-15: venta a crédito de 100 y abono de 40 dejan saldo 60', function () {
    $cliente = Cliente::factory()->create();
    ventaCreditoCerrada($cliente, 1, 100);
    actuarComo(Rol::Mostrador);

    abonarId($cliente, 40);

    $this->getJson("/api/clientes/{$cliente->id}/saldo")->assertOk()
        ->assertJsonPath('cargos', '100.00')
        ->assertJsonPath('abonos', '40.00')
        ->assertJsonPath('saldo', '60.00')
        ->assertJsonPath('a_favor', '0.00');
});

it('RF-15: reversar un ítem de la venta baja el saldo', function () {
    $cliente = Cliente::factory()->create();
    $venta = ventaCreditoCerrada($cliente, 2, 50);
    $admin = actuarComo(Rol::Admin);

    app(ReversoService::class)->reversar($venta, TipoReverso::Devolucion, 'Cliente devolvió una', $venta->items()->value('id'), $admin);

    $this->getJson("/api/clientes/{$cliente->id}/saldo")->assertJsonPath('saldo', '50.00');
});

it('RF-15: anular el abono devuelve el saldo a 100 y el abono sigue visible', function () {
    $cliente = Cliente::factory()->create();
    ventaCreditoCerrada($cliente, 1, 100);
    actuarComo(Rol::Admin);
    $abono = abonarId($cliente, 40);

    $this->postJson("/api/abonos/{$abono}/anulaciones", ['motivo' => 'Consignación rechazada'])
        ->assertCreated()
        ->assertJsonPath('anulacion.motivo', 'Consignación rechazada');

    $this->getJson("/api/clientes/{$cliente->id}/saldo")
        ->assertJsonPath('saldo', '100.00')
        ->assertJsonPath('abonos_recientes.0.id', $abono)
        ->assertJsonPath('abonos_recientes.0.valor', '40.00')
        ->assertJsonPath('abonos_recientes.0.anulacion.motivo', 'Consignación rechazada');
    expect(AbonoCliente::count())->toBe(1);
});

it('RF-15: un abono no se anula dos veces y la anulación exige motivo', function () {
    $cliente = Cliente::factory()->create();
    actuarComo(Rol::Admin);
    $abono = abonarId($cliente, 40);

    $this->postJson("/api/abonos/{$abono}/anulaciones", [])->assertJsonValidationErrors('motivo');
    $this->postJson("/api/abonos/{$abono}/anulaciones", ['motivo' => 'Error'])->assertCreated();
    $this->postJson("/api/abonos/{$abono}/anulaciones", ['motivo' => 'Otra vez'])->assertUnprocessable();
});

it('RF-15 / S-14: el mostrador abona y ve el saldo, pero no anula; logística no entra', function () {
    $cliente = Cliente::factory()->create();
    actuarComo(Rol::Mostrador);
    $abono = abonarId($cliente, 40);

    $this->postJson("/api/abonos/{$abono}/anulaciones", ['motivo' => 'Error'])->assertForbidden();

    actuarComo(Rol::Logistica);
    $this->postJson("/api/clientes/{$cliente->id}/abonos", ['valor' => 10, 'medio' => 'efectivo'])->assertForbidden();
    $this->getJson("/api/clientes/{$cliente->id}/saldo")->assertForbidden();
});

it('RF-15: el abono es inmutable y no tiene ruta para borrarse', function () {
    $cliente = Cliente::factory()->create();
    actuarComo(Rol::Admin);
    $abono = AbonoCliente::find(abonarId($cliente, 40));

    $abono->valor = 10;
    expect(fn () => $abono->save())->toThrow(RegistroInmutableException::class);
    expect(fn () => $abono->delete())->toThrow(RegistroInmutableException::class);
    $this->deleteJson("/api/abonos/{$abono->id}")->assertNotFound();
});

it('RF-15: el valor va en pesos enteros mayores que 0, con un medio de la lista', function () {
    $cliente = Cliente::factory()->create();
    actuarComo(Rol::Mostrador);
    $url = "/api/clientes/{$cliente->id}/abonos";

    $this->postJson($url, ['valor' => 0, 'medio' => 'efectivo'])->assertJsonValidationErrors('valor');
    $this->postJson($url, ['valor' => -5, 'medio' => 'efectivo'])->assertJsonValidationErrors('valor');
    $this->postJson($url, ['valor' => 10.5, 'medio' => 'efectivo'])->assertJsonValidationErrors('valor');
    $this->postJson($url, ['valor' => 10, 'medio' => 'trueque'])->assertJsonValidationErrors('medio');
    $this->postJson($url, ['valor' => 10, 'medio' => 'transferencia', 'referencia' => 'TRX-991'])->assertCreated()
        ->assertJsonPath('referencia', 'TRX-991');
});

it('RF-15: la fecha es hoy por defecto, puede ser anterior y nunca futura', function () {
    Carbon::setTestNow('2026-11-21 10:00:00');
    $cliente = Cliente::factory()->create();
    actuarComo(Rol::Mostrador);
    $url = "/api/clientes/{$cliente->id}/abonos";

    $this->postJson($url, ['valor' => 10, 'medio' => 'efectivo'])->assertJsonPath('fecha', '2026-11-21');
    $this->postJson($url, ['valor' => 10, 'medio' => 'efectivo', 'fecha' => '2026-11-20'])->assertJsonPath('fecha', '2026-11-20');
    $this->postJson($url, ['valor' => 10, 'medio' => 'efectivo', 'fecha' => '2026-11-22'])->assertJsonValidationErrors('fecha');
});

it('RF-15: se puede abonar a un cliente inactivo', function () {
    $cliente = Cliente::factory()->create();
    ventaCreditoCerrada($cliente, 1, 100);
    $cliente->update(['activo' => false]);
    actuarComo(Rol::Mostrador);

    abonarId($cliente, 100);

    $this->getJson("/api/clientes/{$cliente->id}/saldo")->assertJsonPath('saldo', '0.00');
});

it('RF-15: si lo abonado supera lo vendido, la diferencia es saldo a favor', function () {
    $cliente = Cliente::factory()->create();
    ventaCreditoCerrada($cliente, 1, 100);
    actuarComo(Rol::Mostrador);

    abonarId($cliente, 130);

    $this->getJson("/api/clientes/{$cliente->id}/saldo")
        ->assertJsonPath('saldo', '-30.00')
        ->assertJsonPath('a_favor', '30.00')
        ->assertJsonPath('documentos', []);
});

it('RF-15 / S-09: una venta a crédito abierta y una de contado no suman al saldo', function () {
    $cliente = Cliente::factory()->create();
    $mostrador = actuarComo(Rol::Mostrador);
    $servicio = app(VentaService::class);

    $abierta = $servicio->abrir($mostrador, FormaPago::Credito, $cliente->id);
    $servicio->escanear($abierta, unidadDe('RN-4410')->codigo_barras, MetodoCaptura::Escaneado, $mostrador);
    $contado = $servicio->abrir($mostrador);
    $servicio->escanear($contado, unidadDe('RN-4410')->codigo_barras, MetodoCaptura::Escaneado, $mostrador);
    $servicio->cerrar($contado);

    $this->getJson("/api/clientes/{$cliente->id}/saldo")->assertJsonPath('saldo', '0.00');
});

it('RF-15 / S-12 / S-13: el abono se aplica FIFO y lo pendiente cae en su rango de días vencidos', function () {
    $cliente = Cliente::factory()->create(['plazo_dias' => 30]);
    Carbon::setTestNow('2026-09-01 10:00:00');
    $vieja = ventaCreditoCerrada($cliente, 1, 100); // vence 2026-10-01
    Carbon::setTestNow('2026-11-01 10:00:00');
    $nueva = ventaCreditoCerrada($cliente, 1, 100); // vence 2026-12-01
    Carbon::setTestNow('2026-11-21 10:00:00');
    actuarComo(Rol::Mostrador);

    abonarId($cliente, 60);

    $respuesta = $this->getJson("/api/clientes/{$cliente->id}/saldo")
        ->assertJsonPath('saldo', '140.00')
        ->assertJsonPath('vencido', '40.00')
        ->assertJsonPath('rangos.d31_60', '40.00')
        ->assertJsonPath('rangos.al_dia', '100.00');

    expect($respuesta->json('documentos'))->toHaveCount(2)
        ->and($respuesta->json('documentos.0.id'))->toBe($vieja->id)
        ->and($respuesta->json('documentos.0.pendiente'))->toBe('40.00')
        ->and($respuesta->json('documentos.0.dias_vencido'))->toBe(51)
        ->and($respuesta->json('documentos.1.id'))->toBe($nueva->id);
});
