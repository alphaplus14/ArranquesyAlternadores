<?php

use App\Enums\Rol;
use App\Models\CuentaPago;
use Illuminate\Support\Carbon;

/*
 * S-88 (RF-24, RF-25): dueño de una billetera y saldo diario calculado. Las cuentas que ya existen
 * (Nequi, Daviplata, Llaves) siguen sin dueño y se agrupan en "Sin dueño". El efectivo no lleva saldo.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-11-20 15:00:00'); // 10:00 en Bogotá
    sembrarDemo();
    referencia('RN-4410')->update(['precio_venta' => 50000]);
    ingresar(referencia('RN-4410'), 6);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Vende una unidad de RN-4410 (50.000) contra esa cuenta, como el mostrador, y vuelve al usuario anterior. */
function venderEnCuenta(CuentaPago $cuenta): void
{
    $anterior = auth()->user();
    actuarComo(Rol::Mostrador);
    $id = abrirVenta();
    test()->postJson("/api/ventas/{$id}/escanear", [
        'codigo_barras' => unidadDe('RN-4410')->codigo_barras,
        'metodo_captura' => 'escaneado',
    ])->assertCreated();
    cerrarVenta($id, [['cuenta_pago_id' => $cuenta->id, 'valor' => 50000]])->assertOk();
    if ($anterior) {
        test()->actingAs($anterior);
    }
}

function filaBilletera(array $grupos, string $dueno, string $cuenta): array
{
    $grupo = collect($grupos)->firstWhere('propietario', $dueno);
    expect($grupo)->not->toBeNull();

    $fila = collect($grupo['cuentas'])->firstWhere('nombre', $cuenta);
    expect($fila)->not->toBeNull();

    return $fila;
}

it('S-88: distingue el saldo de cada billetera por dueño y lo arrastra al día siguiente', function () {
    actuarComo(Rol::Admin);
    $dayana = $this->postJson('/api/propietarios', ['nombre' => 'Dayana'])->assertCreated()->json('id');
    $evelyn = $this->postJson('/api/propietarios', ['nombre' => 'Evelyn'])->assertCreated()->json('id');
    $nequiDayana = $this->postJson('/api/cuentas-pago', ['nombre' => 'Nequi Dayana', 'tipo' => 'billetera', 'propietario_id' => $dayana])
        ->assertCreated()->assertJsonPath('propietario.nombre', 'Dayana')->json('id');
    $nequiEvelyn = $this->postJson('/api/cuentas-pago', ['nombre' => 'Nequi Evelyn', 'tipo' => 'billetera', 'propietario_id' => $evelyn])->assertCreated()->json('id');
    $this->postJson('/api/cuentas-pago', ['nombre' => 'Daviplata Dayana', 'tipo' => 'billetera', 'propietario_id' => $dayana])->assertCreated();

    $catalogo = collect($this->getJson('/api/cuentas-pago')->assertOk()->json('data'));
    expect($catalogo->firstWhere('nombre', 'Nequi')['propietario'])->toBeNull()
        ->and($catalogo->firstWhere('nombre', 'Efectivo')['propietario'])->toBeNull();

    venderEnCuenta(CuentaPago::findOrFail($nequiDayana));
    venderEnCuenta(CuentaPago::findOrFail($nequiEvelyn));
    venderEnCuenta(CuentaPago::where('nombre', 'Nequi')->firstOrFail());

    $dia = $this->getJson('/api/caja/dias/2026-11-20')->assertOk()->json('resumen');
    $efectivo = collect($dia['cuentas'])->firstWhere('nombre', 'Efectivo');
    expect($efectivo)->not->toHaveKey('saldo');

    expect(filaBilletera($dia['billeteras'], 'Dayana', 'Nequi Dayana'))->toMatchArray([
        'saldo_anterior' => '0.00', 'neto' => '50000.00', 'saldo' => '50000.00',
    ])->and(filaBilletera($dia['billeteras'], 'Dayana', 'Daviplata Dayana')['saldo'])->toBe('0.00')
        ->and(filaBilletera($dia['billeteras'], 'Evelyn', 'Nequi Evelyn')['saldo'])->toBe('50000.00')
        ->and(filaBilletera($dia['billeteras'], 'Sin dueño', 'Nequi')['saldo'])->toBe('50000.00')
        ->and(collect($dia['billeteras'])->firstWhere('propietario', 'Dayana')['saldo'])->toBe('50000.00');

    Carbon::setTestNow('2026-11-21 15:00:00');
    venderEnCuenta(CuentaPago::findOrFail($nequiDayana));

    $siguiente = $this->getJson('/api/caja/dias/2026-11-21')->assertOk()->json('resumen');
    expect(filaBilletera($siguiente['billeteras'], 'Dayana', 'Nequi Dayana'))->toMatchArray([
        'saldo_anterior' => '50000.00', 'neto' => '50000.00', 'saldo' => '100000.00',
    ])->and(filaBilletera($siguiente['billeteras'], 'Evelyn', 'Nequi Evelyn'))->toMatchArray([
        'saldo_anterior' => '50000.00', 'neto' => '0.00', 'saldo' => '50000.00',
    ])->and(filaBilletera($siguiente['billeteras'], 'Sin dueño', 'Nequi')['saldo'])->toBe('50000.00');

    $mes = $this->getJson('/api/caja/meses/2026-11')->assertOk()->json();
    $ventas = collect($mes['dias'])->reduce(fn ($t, $d) => bcadd($t, $d['ventas_billetera'], 2), '0.00');
    expect($ventas)->toBe('200000.00')
        ->and(filaBilletera($mes['billeteras'], 'Dayana', 'Nequi Dayana'))->toMatchArray(['neto' => '100000.00', 'saldo' => '100000.00'])
        ->and(filaBilletera($mes['billeteras'], 'Evelyn', 'Nequi Evelyn')['neto'])->toBe('50000.00')
        ->and(filaBilletera($mes['billeteras'], 'Sin dueño', 'Nequi')['saldo'])->toBe('50000.00');
});

it('S-88: el dueño solo aplica a una billetera, y quien no es admin no lo crea', function () {
    actuarComo(Rol::Admin);
    $dayana = $this->postJson('/api/propietarios', ['nombre' => 'Dayana'])->assertCreated()->json('id');
    $this->postJson('/api/propietarios', ['nombre' => 'Dayana'])->assertUnprocessable()->assertJsonValidationErrors('nombre');
    $this->postJson('/api/cuentas-pago', ['nombre' => 'Bancolombia Dayana', 'tipo' => 'banco', 'propietario_id' => $dayana])
        ->assertUnprocessable()->assertJsonPath('campo', 'propietario_id');

    $cuenta = $this->postJson('/api/cuentas-pago', ['nombre' => 'Nequi Dayana', 'tipo' => 'billetera', 'propietario_id' => $dayana])->assertCreated()->json('id');
    $this->putJson("/api/cuentas-pago/{$cuenta}", ['tipo' => 'banco'])->assertOk()->assertJsonPath('propietario', null);
    $this->assertDatabaseHas('auditoria', ['tabla' => 'cuentas_pago', 'registro_id' => $cuenta, 'campo' => 'propietario_id']);

    actuarComo(Rol::Mostrador);
    $this->getJson('/api/propietarios')->assertOk();
    $this->postJson('/api/propietarios', ['nombre' => 'Evelyn'])->assertForbidden();

    actuarComo(Rol::Logistica);
    $this->getJson('/api/propietarios')->assertForbidden();
    $this->getJson('/api/cuentas-pago')->assertForbidden();
});

it('S-88: el cierre del día del mostrador no trae dueños ni saldos de billeteras; el admin sí', function () {
    actuarComo(Rol::Admin);
    $dayana = $this->postJson('/api/propietarios', ['nombre' => 'Dayana'])->assertCreated()->json('id');
    $nequi = $this->postJson('/api/cuentas-pago', ['nombre' => 'Nequi Dayana', 'tipo' => 'billetera', 'propietario_id' => $dayana])->assertCreated()->json('id');
    venderEnCuenta(CuentaPago::findOrFail($nequi));

    actuarComo(Rol::Mostrador);
    $r = $this->getJson('/api/caja/dias/2026-11-20')->assertOk();
    expect($r->json('resumen'))->not->toHaveKey('billeteras');
    $fila = collect($r->json('resumen.cuentas'))->firstWhere('nombre', 'Nequi Dayana');
    expect($fila['neto'])->toBe('50000.00')
        ->and($fila)->not->toHaveKeys(['propietario', 'propietario_id', 'saldo', 'saldo_anterior']);

    actuarComo(Rol::Admin);
    $r = $this->getJson('/api/caja/dias/2026-11-20')->assertOk();
    $fila = collect($r->json('resumen.cuentas'))->firstWhere('nombre', 'Nequi Dayana');
    expect($r->json('resumen.billeteras'))->not->toBeEmpty()
        ->and($fila['propietario'])->toBe('Dayana')
        ->and($fila['saldo'])->toBe('50000.00');
});
