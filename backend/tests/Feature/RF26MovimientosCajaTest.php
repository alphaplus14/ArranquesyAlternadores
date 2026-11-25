<?php

use App\Enums\Rol;
use App\Exceptions\RegistroInmutableException;
use App\Models\CategoriaCaja;
use App\Models\CuentaPago;
use App\Models\MovimientoCaja;
use App\Services\CajaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/*
 * RF-26 (S-51 a S-54): movimientos de caja (gastos, gastos fijos, cuotas bancarias, ingresos externos
 * y otros ingresos). Inmutables, anulación con motivo, egresos solo en efectivo y permisos por rol.
 */

beforeEach(function () {
    sembrarDemo();
    Carbon::setTestNow('2026-11-20 15:00:00'); // 10:00 en Bogotá
    app(CajaService::class)->fijarSaldoInicial('100000', '2026-11-01');
});

it('RF-26: el seeder crea las categorías de caja por grupo, sin DCTO NEVERA', function () {
    $grupos = CategoriaCaja::all()->groupBy(fn ($c) => $c->grupo->value)->map->count();

    expect($grupos['fijo'])->toBe(20)
        ->and($grupos['financiero'])->toBe(4)
        ->and($grupos['ingreso_externo'])->toBe(2)
        ->and($grupos['otro_ingreso'])->toBe(3)
        ->and(CategoriaCaja::where('nombre', 'like', '%NEVERA%')->exists())->toBeFalse();
});

it('RF-26: el mostrador registra un gasto y queda con el grupo de su categoría, su usuario y su fecha', function () {
    $mostrador = actuarComo(Rol::Mostrador);

    movimientoCaja('EMPRESA', 12000, extra: ['referencia' => 'FVE-55'])->assertCreated()
        ->assertJsonPath('grupo', 'vario')
        ->assertJsonPath('fecha', '2026-11-20')
        ->assertJsonPath('valor', '12000.00')
        ->assertJsonPath('referencia', 'FVE-55')
        ->assertJsonPath('usuario.id', $mostrador->id);

    $this->getJson('/api/caja/movimientos?fecha=2026-11-20')->assertOk()->assertJsonCount(1, 'data');
});

it('RF-26 / S-52: gastos, gastos fijos, cuotas e ingresos externos solo en efectivo; otro ingreso en cualquier cuenta', function () {
    actuarComo(Rol::Mostrador);
    $nequi = CuentaPago::where('nombre', 'Nequi')->firstOrFail();

    foreach (['EMPRESA', 'Plan celulares', 'Banco Agrario', 'CAJA GRAL'] as $categoria) {
        movimientoCaja($categoria, 1000, $nequi)->assertUnprocessable()->assertJsonPath('campo', 'cuenta_pago_id');
    }
    movimientoCaja('SERVICIO DE TORNO', 1000, $nequi)->assertCreated()->assertJsonPath('cuenta.nombre', 'Nequi');
});

it('RF-26: valida valor, concepto, categoría activa y fecha no futura', function () {
    actuarComo(Rol::Mostrador);

    movimientoCaja('EMPRESA', 0)->assertUnprocessable()->assertJsonValidationErrors('valor');
    movimientoCaja('EMPRESA', 1000, extra: ['concepto' => ''])->assertUnprocessable()->assertJsonValidationErrors('concepto');
    movimientoCaja('EMPRESA', 1000, extra: ['fecha' => '2026-11-21'])->assertUnprocessable()->assertJsonPath('message', 'La fecha no puede ser futura.');
    movimientoCaja('EMPRESA', 1000, extra: ['fecha' => '2026-10-31'])->assertUnprocessable(); // antes del saldo inicial
    movimientoCaja('EMPRESA', 1000, extra: ['fecha' => '2026-11-19'])->assertCreated(); // un día anterior sin cierre

    categoriaCaja('ENCOMIENDAS')->update(['activa' => false]);
    movimientoCaja('ENCOMIENDAS', 1000)->assertUnprocessable()->assertJsonPath('message', 'Elija una categoría activa.');
});

it('RF-26 / S-53: un movimiento es inmutable; se anula con motivo, una sola vez, y sigue visible', function () {
    actuarComo(Rol::Mostrador);
    $id = movimientoCaja('EMPRESA', 5000)->json('id');
    $mov = MovimientoCaja::findOrFail($id);

    $mov->valor = 1;
    expect(fn () => $mov->save())->toThrow(RegistroInmutableException::class)
        ->and(fn () => MovimientoCaja::findOrFail($id)->delete())->toThrow(RegistroInmutableException::class);

    $this->postJson("/api/caja/movimientos/{$id}/anulaciones", [])->assertUnprocessable()->assertJsonValidationErrors('motivo');
    $this->postJson("/api/caja/movimientos/{$id}/anulaciones", ['motivo' => 'Valor equivocado'])->assertCreated()
        ->assertJsonPath('anulacion.motivo', 'Valor equivocado');
    $this->postJson("/api/caja/movimientos/{$id}/anulaciones", ['motivo' => 'Otra vez'])->assertUnprocessable()
        ->assertJsonPath('message', 'Este movimiento ya fue anulado.');

    $this->getJson('/api/caja/movimientos?fecha=2026-11-20')->assertJsonPath('data.0.anulacion.motivo', 'Valor equivocado');
    $this->getJson('/api/caja/dias/2026-11-20')->assertJsonPath('resumen.egresos.total', '0.00');
});

it('RF-26 / S-53: con el día cerrado no se registran ni se anulan movimientos de ese día ni de antes', function () {
    actuarComo(Rol::Mostrador);
    $id = movimientoCaja('EMPRESA', 5000)->json('id');
    $this->postJson('/api/caja/cierres', ['fecha' => '2026-11-20', 'efectivo_contado' => 0])->assertCreated();

    movimientoCaja('EMPRESA', 1000)->assertUnprocessable()->assertJsonPath('campo', 'fecha');
    movimientoCaja('EMPRESA', 1000, extra: ['fecha' => '2026-11-19'])->assertUnprocessable(); // anterior a un cierre
    $this->postJson("/api/caja/movimientos/{$id}/anulaciones", ['motivo' => 'Tarde'])->assertUnprocessable();
});

it('RF-26 / S-54: logística recibe 403 en toda la caja', function () {
    actuarComo(Rol::Logistica);

    $this->getJson('/api/caja/movimientos')->assertForbidden();
    $this->getJson('/api/caja/categorias')->assertForbidden();
    $this->getJson('/api/caja/dias/2026-11-20')->assertForbidden();
    movimientoCaja('EMPRESA', 1000)->assertForbidden();
    $this->postJson('/api/caja/cierres', ['fecha' => '2026-11-20', 'efectivo_contado' => 0])->assertForbidden();
    $this->getJson('/api/caja/meses/2026-11')->assertForbidden();
});

it('RF-26 / S-54: el admin consulta pero no registra, no anula ni cierra; sí gestiona las categorías', function () {
    actuarComo(Rol::Mostrador);
    $id = movimientoCaja('EMPRESA', 5000)->json('id');

    actuarComo(Rol::Admin);
    $this->getJson('/api/caja/movimientos?fecha=2026-11-20')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/caja/dias/2026-11-20')->assertOk();
    movimientoCaja('EMPRESA', 1000)->assertForbidden();
    $this->postJson("/api/caja/movimientos/{$id}/anulaciones", ['motivo' => 'No'])->assertForbidden();
    $this->postJson('/api/caja/cierres', ['fecha' => '2026-11-20', 'efectivo_contado' => 0])->assertForbidden();

    $cat = $this->postJson('/api/caja/categorias', ['nombre' => 'PAPELERÍA', 'grupo' => 'vario'])->assertCreated()->json('id');
    $this->postJson('/api/caja/categorias', ['nombre' => 'PAPELERÍA', 'grupo' => 'vario'])->assertUnprocessable()->assertJsonValidationErrors('nombre');
    $this->putJson("/api/caja/categorias/{$cat}", ['activa' => false])->assertOk()->assertJsonPath('activa', false);
    $this->getJson('/api/caja/categorias?incluir_inactivas=1')->assertJsonFragment(['nombre' => 'PAPELERÍA', 'activa' => false]);
    $this->assertDatabaseHas('auditoria', ['tabla' => 'categorias_caja', 'registro_id' => $cat, 'campo' => 'activa']);

    actuarComo(Rol::Mostrador);
    $this->postJson('/api/caja/categorias', ['nombre' => 'OTRA', 'grupo' => 'vario'])->assertForbidden();
    $this->putJson("/api/caja/categorias/{$cat}", ['activa' => true])->assertForbidden();
    expect(collect($this->getJson('/api/caja/categorias')->json('data'))->pluck('nombre'))->not->toContain('PAPELERÍA');
});

it('RF-24 a RF-26: no hay rutas DELETE de caja, cuentas, categorías, cierres ni pagos de venta', function () {
    $borrables = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => in_array('DELETE', $r->methods(), true))
        ->map(fn ($r) => $r->uri())
        ->filter(fn ($uri) => str_contains($uri, 'caja') || str_contains($uri, 'cuentas-pago') || str_contains($uri, 'cierres') || str_contains($uri, 'pagos'));

    expect($borrables->all())->toBe([]);
});
