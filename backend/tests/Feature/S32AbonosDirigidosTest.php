<?php

use App\Enums\Rol;
use App\Models\AbonoCliente;
use App\Models\Cliente;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * RF-15 / RF-18 (S-32, reemplaza S-12): abonos generales (FIFO) o dirigidos a una venta a crédito.
 */

beforeEach(fn () => sembrarDemo());

/** Cliente con dos ventas a crédito cerradas: vieja de 60 (vencida hace 10 días) y nueva de 50 (vence en 20). */
function dosVentas(): array
{
    $cliente = Cliente::factory()->create();
    $hoy = Carbon::now('America/Bogota')->startOfDay();
    [$vieja, $itemsVieja] = ventaCredito($cliente, [60]);
    [$nueva, $itemsNueva] = ventaCredito($cliente, [30, 20]);
    fecharVenta($vieja, $hoy->copy()->subDays(40)->toDateString(), $hoy->copy()->subDays(10)->toDateString());
    fecharVenta($nueva, $hoy->copy()->subDays(10)->toDateString(), $hoy->copy()->addDays(20)->toDateString());

    return [$cliente, $vieja, $nueva, $itemsNueva];
}

/** [pendiente por venta id, resumen] del detalle. */
function cartera(Cliente $cliente): array
{
    $r = test()->getJson("/api/clientes/{$cliente->id}/cartera")->assertOk();

    return [collect($r->json('ventas'))->keyBy('id'), $r->json('resumen')];
}

it('S-32 (a): un abono general de 40 se aplica a la venta más antigua: pendientes 20 y 50', function () {
    [$cliente, $vieja, $nueva] = dosVentas();
    actuarComo(Rol::Mostrador);

    abonar($cliente, 40)->assertCreated()
        ->assertJsonPath('abono.venta_id', null)
        ->assertJsonPath('abono.aplicado_a', 'General');

    [$ventas, $resumen] = cartera($cliente);
    expect($ventas[$vieja]['pendiente'])->toBe('20.00')
        ->and($ventas[$vieja]['aplicado_general'])->toBe('40.00')
        ->and($ventas[$vieja]['aplicado_dirigido'])->toBe('0.00')
        ->and($ventas[$nueva]['pendiente'])->toBe('50.00')
        ->and($resumen['saldo'])->toBe('70.00');
});

it('S-32 (b): un abono dirigido de 50 salda la venta más reciente y la más antigua conserva su pendiente', function () {
    [$cliente, $vieja, $nueva] = dosVentas();
    actuarComo(Rol::Mostrador);

    abonar($cliente, 50, ['venta_id' => $nueva, 'referencia' => 'TR-9'])->assertCreated()
        ->assertJsonPath('abono.venta_id', $nueva)
        ->assertJsonPath('abono.aplicado_a', "Venta #{$nueva}")
        ->assertJsonPath('resumen.saldo', '60.00')
        ->assertJsonPath('resumen.vencido', '60.00');

    [$ventas] = cartera($cliente);
    expect($ventas[$nueva]['pendiente'])->toBe('0.00')
        ->and($ventas[$nueva]['aplicado_dirigido'])->toBe('50.00')
        ->and($ventas[$nueva]['estado'])->toBe('al_dia')
        ->and($ventas[$vieja]['pendiente'])->toBe('60.00')
        ->and($ventas[$vieja]['estado'])->toBe('vencida');

    // La factura de Contapyme aparece en "aplicado a" y en el estado de cuenta.
    DB::table('ventas')->where('id', $nueva)->update(['factura_contapyme' => 'FE-1042']);
    $this->getJson("/api/clientes/{$cliente->id}/cartera")->assertJsonPath('abonos.0.aplicado_a', "Venta #{$nueva} · FE-1042");
    $mov = collect($this->getJson("/api/clientes/{$cliente->id}/estado-cuenta")->json('movimientos'))->firstWhere('tipo', 'abono');
    expect($mov['aplicado_a'])->toBe("Venta #{$nueva} · FE-1042")->and($mov['venta_id'])->toBe($nueva);
});

it('S-32 (c): dirigido mayor al pendiente, a una venta de otro cliente, abierta o de contado: 422 sin crear el abono', function () {
    [$cliente, $vieja, $nueva] = dosVentas();
    $otro = Cliente::factory()->create();
    [$ajena] = ventaCredito($otro, [10]);
    [$abierta] = ventaCredito($cliente, [10], false);

    actuarComo(Rol::Mostrador);
    $contado = abrirVenta();
    $this->postJson("/api/ventas/{$contado}/cerrar")->assertOk();

    abonar($cliente, 51, ['venta_id' => $nueva])->assertUnprocessable()
        ->assertJsonPath('message', "El abono supera lo pendiente de la venta #{$nueva} (\$50). Registre hasta \$50 a esta venta y el resto como abono general.")
        ->assertJsonPath('campo', 'valor');
    abonar($cliente, 5, ['venta_id' => $ajena])->assertUnprocessable()->assertJsonPath('message', "La venta #{$ajena} no es de este cliente.");
    abonar($cliente, 5, ['venta_id' => $abierta])->assertUnprocessable()->assertJsonPath('message', "La venta #{$abierta} está abierta: ciérrela antes de abonarle.");
    DB::table('ventas')->where('id', $contado)->update(['cliente_id' => $cliente->id]);
    abonar($cliente, 5, ['venta_id' => $contado])->assertUnprocessable()->assertJsonPath('message', "La venta #{$contado} es de contado: no recibe abonos.");
    abonar($cliente, 5, ['venta_id' => 999999])->assertUnprocessable();

    expect(AbonoCliente::where('cliente_id', $cliente->id)->count())->toBe(0);
});

it('S-32 (d): dirigir a una venta ya pagada (sin pendiente) responde 422', function () {
    [$cliente, $vieja] = dosVentas();
    actuarComo(Rol::Mostrador);

    // Un general de 60 salda la vieja por FIFO.
    abonar($cliente, 60)->assertCreated();
    abonar($cliente, 1, ['venta_id' => $vieja])->assertUnprocessable()
        ->assertJsonPath('message', "La venta #{$vieja} no tiene saldo pendiente.");
});

it('S-32 (e): un reverso posterior deja un dirigido de más: el excedente baja el pendiente de la más antigua', function () {
    [$cliente, $vieja, $nueva, $itemsNueva] = dosVentas();
    actuarComo(Rol::Mostrador);

    abonar($cliente, 50, ['venta_id' => $nueva])->assertCreated();
    // Devolución del ítem de 20 de la venta nueva: su neto pasa a 30 y sobran 20 dirigidos.
    $this->postJson("/api/ventas/{$nueva}/reversos", ['tipo' => 'devolucion', 'motivo' => 'No le sirvió', 'venta_item_id' => $itemsNueva[1]])->assertOk();

    [$ventas, $resumen] = cartera($cliente);
    expect($ventas[$nueva]['total'])->toBe('30.00')
        ->and($ventas[$nueva]['aplicado_dirigido'])->toBe('30.00')
        ->and($ventas[$nueva]['pendiente'])->toBe('0.00')
        ->and($ventas[$vieja]['aplicado_general'])->toBe('20.00')
        ->and($ventas[$vieja]['pendiente'])->toBe('40.00')
        // Mismo saldo que sin dirigir: 60 + 30 − 50.
        ->and($resumen['saldo'])->toBe('40.00')
        ->and($resumen['vencido'])->toBe('40.00');
});

it('S-32 (f): anular un abono dirigido devuelve el pendiente a su venta y el abono sigue visible y marcado', function () {
    [$cliente, $vieja, $nueva] = dosVentas();
    actuarComo(Rol::Mostrador);
    $abonoId = abonar($cliente, 30, ['venta_id' => $nueva])->assertCreated()->json('abono.id');

    actuarComo(Rol::Admin);
    $this->postJson("/api/abonos/{$abonoId}/anulaciones", ['motivo' => 'Era para otra factura'])->assertCreated()
        ->assertJsonPath('resumen.saldo', '110.00');

    [$ventas] = cartera($cliente);
    $r = $this->getJson("/api/clientes/{$cliente->id}/cartera");
    expect($ventas[$nueva]['pendiente'])->toBe('50.00')
        ->and($ventas[$nueva]['aplicado_dirigido'])->toBe('0.00')
        ->and($r->json('abonos.0.id'))->toBe($abonoId)
        ->and($r->json('abonos.0.anulado'))->toBeTrue()
        ->and($r->json('abonos.0.aplicado_a'))->toBe("Venta #{$nueva}");
});

it('S-32 (g): el saldo del cliente es idéntico dirigiendo o no los abonos', function () {
    [$dirigido, , $nuevaD] = dosVentas();
    [$general] = dosVentas();
    actuarComo(Rol::Mostrador);

    abonar($dirigido, 45, ['venta_id' => $nuevaD])->assertCreated();
    abonar($dirigido, 25)->assertCreated();
    abonar($general, 45)->assertCreated();
    abonar($general, 25)->assertCreated();

    [$vD, $rD] = cartera($dirigido);
    [$vG, $rG] = cartera($general);
    $suma = fn ($v) => number_format($v->sum(fn ($x) => (float) $x['pendiente']), 2, '.', '');
    expect($rD['saldo'])->toBe('40.00')->and($rG['saldo'])->toBe('40.00')
        ->and($suma($vD))->toBe('40.00')->and($suma($vG))->toBe('40.00')
        // Cambia el reparto: dirigido deja la vieja con 35 vencidos; general la salda.
        ->and($rD['vencido'])->toBe('35.00')->and($rG['vencido'])->toBe('0.00');
});

it('S-32 (h): un abono general mayor al saldo total sigue dejando saldo a favor', function () {
    [$cliente, $vieja, $nueva] = dosVentas();
    actuarComo(Rol::Mostrador);

    abonar($cliente, 50, ['venta_id' => $nueva])->assertCreated();
    abonar($cliente, 100)->assertCreated()
        ->assertJsonPath('resumen.saldo', '-40.00')
        ->assertJsonPath('resumen.saldo_a_favor', true);

    [$ventas] = cartera($cliente);
    expect($ventas->every(fn ($v) => $v['pendiente'] === '0.00'))->toBeTrue();
});

it('S-32 (i): los filtros de estado y el vencido de la lista coinciden con el detalle', function () {
    [$cliente, $vieja, $nueva] = dosVentas();
    actuarComo(Rol::Mostrador);
    abonar($cliente, 50, ['venta_id' => $nueva])->assertCreated();

    $ruta = "/api/clientes/{$cliente->id}/cartera";
    $ids = fn (string $estado) => collect($this->getJson("{$ruta}?estado={$estado}")->json('ventas'))->pluck('id')->all();
    expect($ids('pagadas'))->toBe([$nueva])
        ->and($ids('pendientes'))->toBe([$vieja])
        ->and($ids('vencidas'))->toBe([$vieja]);

    [, $resumen] = cartera($cliente);
    $fila = collect($this->getJson('/api/cartera?solo_con_saldo=0')->json('data'))->firstWhere('cliente.id', $cliente->id);
    expect($fila['vencido'])->toBe($resumen['vencido'])
        ->and($fila['vencido'])->toBe('60.00')
        ->and($fila['antiguedad'])->toBe($resumen['antiguedad'])
        ->and($fila['proximo_vencimiento'])->toBeNull();
});

it('S-32 (j): mostrador y admin registran abonos dirigidos; solo admin anula; logística 403; sin PUT ni DELETE', function () {
    [$cliente, $vieja, $nueva] = dosVentas();

    actuarComo(Rol::Logistica);
    abonar($cliente, 5, ['venta_id' => $nueva])->assertForbidden();

    actuarComo(Rol::Mostrador);
    $id = abonar($cliente, 5, ['venta_id' => $nueva])->assertCreated()->json('abono.id');
    $this->postJson("/api/abonos/{$id}/anulaciones", ['motivo' => 'Error'])->assertForbidden();
    // No hay ruta para editar ni borrar abonos (el vínculo no se edita).
    expect($this->putJson("/api/abonos/{$id}", ['venta_id' => $vieja])->status())->toBeIn([404, 405])
        ->and($this->patchJson("/api/abonos/{$id}", ['venta_id' => $vieja])->status())->toBeIn([404, 405])
        ->and($this->deleteJson("/api/abonos/{$id}")->status())->toBeIn([404, 405]);

    actuarComo(Rol::Admin);
    abonar($cliente, 5, ['venta_id' => $nueva])->assertCreated();
    $this->postJson("/api/abonos/{$id}/anulaciones", ['motivo' => 'Error'])->assertCreated();

    expect(AbonoCliente::find($id)->venta_id)->toBe($nueva);
});

it('S-32: la fecha de un abono dirigido no puede ser anterior al cierre de la venta', function () {
    [$cliente, , $nueva] = dosVentas();
    actuarComo(Rol::Mostrador);

    $antes = Carbon::now('America/Bogota')->subDays(11)->toDateString();
    abonar($cliente, 5, ['venta_id' => $nueva, 'fecha' => $antes])->assertUnprocessable()
        ->assertJsonPath('campo', 'fecha');
});
