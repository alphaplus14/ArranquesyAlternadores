<?php

use App\Enums\Rol;
use App\Models\Cliente;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * RF-15 / RF-18 / RF-14 (S-30): buscar y filtrar las ventas a crédito de un cliente por n.º de venta,
 * factura de Contapyme, estado (FIFO) y período de cierre; y encontrar al cliente desde la lista de
 * cartera por una de sus ventas.
 */

beforeEach(fn () => sembrarDemo());

function facturar(int $ventaId, ?string $factura): void
{
    DB::table('ventas')->where('id', $ventaId)->update(['factura_contapyme' => $factura]);
}

function idsVentas($respuesta): array
{
    return collect($respuesta->json('ventas'))->pluck('id')->all();
}

it('RF-15 (a): buscar por n.º de venta, con o sin "#", devuelve solo esa venta del cliente', function () {
    $cliente = Cliente::factory()->create();
    [$v1] = ventaCredito($cliente, [10]);
    [$v2] = ventaCredito($cliente, [20]);
    [$v3] = ventaCredito($cliente, [30]);
    facturar($v1, 'FE-100');
    facturar($v3, 'FE-300');

    actuarComo(Rol::Mostrador);
    foreach (["#{$v2}", (string) $v2, "  # {$v2} "] as $termino) {
        $r = $this->getJson("/api/clientes/{$cliente->id}/cartera?busqueda=".urlencode($termino))->assertOk();
        expect(idsVentas($r))->toBe([$v2])
            ->and($r->json('ventas_total'))->toBe(3);
    }

    // Una venta de otro cliente no aparece aunque se busque su número.
    $otro = Cliente::factory()->create();
    [$ajena] = ventaCredito($otro, [40]);
    $r = $this->getJson("/api/clientes/{$cliente->id}/cartera?busqueda=%23{$ajena}")->assertOk();
    expect(idsVentas($r))->toBe([]);
});

it('RF-15 (b): buscar por prefijo de factura Contapyme, sin distinguir mayúsculas ni espacios', function () {
    $cliente = Cliente::factory()->create();
    [$v1] = ventaCredito($cliente, [10]);
    [$v2] = ventaCredito($cliente, [20]);
    [$v3] = ventaCredito($cliente, [30]);
    facturar($v1, 'FE-1545');
    facturar($v2, 'FE-1546');
    facturar($v3, 'XFE-1545');

    actuarComo(Rol::Mostrador);
    $r = $this->getJson("/api/clientes/{$cliente->id}/cartera?busqueda=".urlencode('  fe-154 '))->assertOk();
    expect(idsVentas($r))->toBe([$v1, $v2])
        ->and($r->json('ventas.0.factura_contapyme'))->toBe('FE-1545');

    // Prefijo, no "contiene": "1545" no encuentra "FE-1545".
    $r = $this->getJson("/api/clientes/{$cliente->id}/cartera?busqueda=1545")->assertOk();
    expect(idsVentas($r))->toBe([]);

    // Un % del usuario es literal, no comodín.
    $r = $this->getJson("/api/clientes/{$cliente->id}/cartera?busqueda=".urlencode('%1545'))->assertOk();
    expect(idsVentas($r))->toBe([]);
});

it('RF-15 (c): pendientes, vencidas y pagadas usan el FIFO de todas las ventas del cliente', function () {
    $cliente = Cliente::factory()->create();
    $hoy = Carbon::now('America/Bogota')->startOfDay();
    [$vieja] = ventaCredito($cliente, [60]);
    [$media] = ventaCredito($cliente, [50]);
    [$nueva] = ventaCredito($cliente, [30]);
    fecharVenta($vieja, $hoy->copy()->subDays(70)->toDateString(), $hoy->copy()->subDays(40)->toDateString());
    fecharVenta($media, $hoy->copy()->subDays(50)->toDateString(), $hoy->copy()->subDays(20)->toDateString());
    fecharVenta($nueva, $hoy->copy()->subDays(5)->toDateString(), $hoy->copy()->addDays(25)->toDateString());

    actuarComo(Rol::Mostrador);
    // 70 salda la vieja (60) y deja 10 en la media: media 40 pendiente y vencida; nueva 30 por vencer.
    abonar($cliente, 70)->assertCreated();

    $ruta = "/api/clientes/{$cliente->id}/cartera";
    expect(idsVentas($this->getJson("{$ruta}?estado=pagadas")->assertOk()))->toBe([$vieja])
        ->and(idsVentas($this->getJson("{$ruta}?estado=pendientes")->assertOk()))->toBe([$media, $nueva])
        ->and(idsVentas($this->getJson("{$ruta}?estado=vencidas")->assertOk()))->toBe([$media])
        ->and(idsVentas($this->getJson("{$ruta}?estado=todas")->assertOk()))->toBe([$vieja, $media, $nueva]);

    // Filtrar no recalcula el FIFO sobre el subconjunto: la media sigue con 40 pendiente.
    $this->getJson("{$ruta}?estado=vencidas")->assertJsonPath('ventas.0.pendiente', '40.00');
    // Combinado con la búsqueda: la vieja (pagada) no sale en "pendientes".
    expect(idsVentas($this->getJson("{$ruta}?estado=pendientes&busqueda=%23{$vieja}")))->toBe([]);

    $this->getJson("{$ruta}?estado=otra")->assertUnprocessable()->assertJsonValidationErrors('estado');
});

it('RF-15 (d): el período filtra por fecha de cierre (día de Bogotá) y "hasta" incluye el día completo', function () {
    $cliente = Cliente::factory()->create();
    [$antes] = ventaCredito($cliente, [10]);
    [$tarde] = ventaCredito($cliente, [20]);
    [$despues] = ventaCredito($cliente, [30]);
    // 9 de octubre, 11 p. m. Bogotá (10 de octubre en UTC): es del 9.
    DB::table('ventas')->where('id', $antes)->update(['cerrada_at' => '2026-10-10 04:00:00', 'vence_el' => '2026-11-08']);
    // 10 de octubre, 11:30 p. m. Bogotá (11 de octubre en UTC): es del 10.
    DB::table('ventas')->where('id', $tarde)->update(['cerrada_at' => '2026-10-11 04:30:00', 'vence_el' => '2026-11-09']);
    DB::table('ventas')->where('id', $despues)->update(['cerrada_at' => '2026-10-11 15:00:00', 'vence_el' => '2026-11-10']);

    actuarComo(Rol::Mostrador);
    $ruta = "/api/clientes/{$cliente->id}/cartera";
    expect(idsVentas($this->getJson("{$ruta}?desde=2026-10-10&hasta=2026-10-10")->assertOk()))->toBe([$tarde])
        ->and(idsVentas($this->getJson("{$ruta}?hasta=2026-10-09")))->toBe([$antes])
        ->and(idsVentas($this->getJson("{$ruta}?desde=2026-10-11")))->toBe([$despues]);

    $this->getJson("{$ruta}?desde=2026-10-11&hasta=2026-10-10")->assertUnprocessable()->assertJsonValidationErrors('hasta');
});

it('RF-15 (e): el saldo, el vencido y la antigüedad del encabezado no cambian con los filtros', function () {
    $cliente = Cliente::factory()->create();
    $hoy = Carbon::now('America/Bogota')->startOfDay();
    [$vieja] = ventaCredito($cliente, [60]);
    [$nueva] = ventaCredito($cliente, [50]);
    fecharVenta($vieja, $hoy->copy()->subDays(70)->toDateString(), $hoy->copy()->subDays(40)->toDateString());
    fecharVenta($nueva, $hoy->copy()->subDays(5)->toDateString(), $hoy->copy()->addDays(25)->toDateString());
    facturar($nueva, 'FE-9');

    actuarComo(Rol::Mostrador);
    $ruta = "/api/clientes/{$cliente->id}/cartera";
    $sin = $this->getJson($ruta)->assertOk()->json('resumen');
    expect($sin['saldo'])->toBe('110.00')->and($sin['vencido'])->toBe('60.00');

    foreach (['?estado=pagadas', '?busqueda=FE-9', '?desde=2000-01-01&hasta=2000-01-02', "?busqueda=%23{$vieja}&estado=vencidas"] as $filtro) {
        expect($this->getJson($ruta.$filtro)->assertOk()->json('resumen'))->toBe($sin);
    }
});

it('RF-15 (f): GET /cartera?busqueda=<factura o n.º> devuelve al dueño de la venta e indica la coincidencia', function () {
    $duenio = Cliente::factory()->create(['nombre' => 'Zeta Motores']);
    $otro = Cliente::factory()->create(['nombre' => 'Alfa Repuestos']);
    [$suya] = ventaCredito($duenio, [100]);
    [$ajena] = ventaCredito($otro, [80]);
    facturar($suya, 'FE-1545');
    facturar($ajena, 'FE-2000');

    actuarComo(Rol::Mostrador);
    $r = $this->getJson('/api/cartera?busqueda=fe-15')->assertOk();
    expect(collect($r->json('data'))->pluck('cliente.id')->all())->toBe([$duenio->id]);
    $r->assertJsonPath('data.0.coincidencia.venta_id', $suya)
        ->assertJsonPath('data.0.coincidencia.factura_contapyme', 'FE-1545')
        ->assertJsonPath('totales.cartera_total', '100.00');

    // Por n.º de venta, con "#".
    $r = $this->getJson('/api/cartera?busqueda=%23'.$ajena)->assertOk();
    expect(collect($r->json('data'))->pluck('cliente.id')->all())->toBe([$otro->id]);
    $r->assertJsonPath('data.0.coincidencia.venta_id', $ajena);

    // La búsqueda por nombre sigue igual y no trae coincidencia de venta.
    $r = $this->getJson('/api/cartera?busqueda=Alfa')->assertOk();
    expect(collect($r->json('data'))->pluck('cliente.id')->all())->toBe([$otro->id])
        ->and($r->json('data.0.coincidencia'))->toBeNull();

    // Una venta abierta no cuenta: su factura no encuentra al cliente.
    $tercero = Cliente::factory()->create();
    [$abierta] = ventaCredito($tercero, [10], false);
    facturar($abierta, 'FE-7777');
    expect($this->getJson('/api/cartera?busqueda=FE-7777')->json('data'))->toBe([]);
});

it('RF-15 (g): logística 403 en el detalle filtrado; mostrador y admin 200', function () {
    $cliente = Cliente::factory()->create();
    ventaCredito($cliente, [10]);
    $ruta = "/api/clientes/{$cliente->id}/cartera?estado=pendientes&busqueda=1&desde=2026-01-01";

    actuarComo(Rol::Logistica);
    $this->getJson($ruta)->assertForbidden();
    $this->getJson('/api/cartera?busqueda=FE')->assertForbidden();

    actuarComo(Rol::Mostrador);
    $this->getJson($ruta)->assertOk();

    actuarComo(Rol::Admin);
    $this->getJson($ruta)->assertOk();
    $this->getJson('/api/cartera?busqueda=FE')->assertOk();
});
