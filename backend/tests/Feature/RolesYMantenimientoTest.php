<?php

use App\Enums\Rol;
use App\Models\Referencia;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => sembrarDemo());

it('roles: el mostrador no entra a logística ni al catálogo de admin', function () {
    actuarComo(Rol::Mostrador);

    $this->postJson('/api/ingresos', [])->assertForbidden();
    $this->postJson('/api/etiquetas', [])->assertForbidden();
    $this->postJson('/api/referencias', [])->assertForbidden();
    $this->getJson('/api/usuarios')->assertForbidden();
});

it('roles: logística no vende ni edita el catálogo', function () {
    actuarComo(Rol::Logistica);

    $this->postJson('/api/ventas')->assertForbidden();
    $this->patchJson('/api/referencias/'.referencia('RN-4410')->id, ['precio_venta' => 1])->assertForbidden();
});

it('roles: el admin entra a todos los módulos', function () {
    actuarComo(Rol::Admin);

    $this->postJson('/api/ventas')->assertCreated();
    $this->getJson('/api/ingresos')->assertOk();
    $this->getJson('/api/usuarios')->assertOk();
});

it('roles: sin sesión responde 401 y un usuario inactivo pierde el acceso', function () {
    $this->getJson('/api/me')->assertUnauthorized();

    $inactivo = User::factory()->rol(Rol::Mostrador)->create(['activo' => false]);
    $this->actingAs($inactivo)->getJson('/api/me')->assertUnauthorized();
});

it('auth: login con usuario inactivo falla', function () {
    User::factory()->rol(Rol::Mostrador)->create(['email' => 'x@la8va.local', 'activo' => false]);

    $this->postJson('/api/login', ['email' => 'x@la8va.local', 'password' => 'password'])->assertUnprocessable();
});

it('inventario:recalcular no reporta diferencias tras los ingresos y ventas normales', function () {
    $this->artisan('inventario:recalcular')->assertSuccessful();
});

it('inventario:recalcular detecta un descuadre provocado a mano y lo corrige con --corregir', function () {
    $referencia = referencia('RN-4410');
    DB::table('referencias')->where('id', $referencia->id)->update(['existencia' => 9]);

    $this->artisan('inventario:recalcular')->assertFailed();
    $this->artisan('inventario:recalcular --corregir')->assertFailed();

    expect(Referencia::find($referencia->id)->existencia)->toBe(2);
    $this->assertDatabaseHas('auditoria', ['tabla' => 'referencias', 'registro_id' => $referencia->id, 'campo' => 'existencia', 'valor_anterior' => '9', 'valor_nuevo' => '2']);
    $this->artisan('inventario:recalcular')->assertSuccessful();
});

it('velocidad: los listados no tienen N+1 (preventLazyLoading activo)', function () {
    actuarComo(Rol::Admin);

    expect(\Illuminate\Database\Eloquent\Model::preventsLazyLoading())->toBeTrue();

    DB::enableQueryLog();
    $this->getJson('/api/referencias')->assertOk();
    $pocas = count(DB::getQueryLog());

    Referencia::factory()->count(30)->create();
    DB::flushQueryLog();
    $this->getJson('/api/referencias')->assertOk();

    // Más filas no significan más consultas.
    expect(count(DB::getQueryLog()))->toBe($pocas);
});
