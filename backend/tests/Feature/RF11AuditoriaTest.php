<?php

use App\Enums\Rol;
use App\Exceptions\RegistroInmutableException;
use App\Models\Auditoria;
use App\Models\Marca;

beforeEach(fn () => sembrarDemo());

it('RF-11: cambiar precio y descripción de una referencia deja el antes, el después, quién y cuándo', function () {
    $admin = actuarComo(Rol::Admin);
    $referencia = referencia('RN-4410');

    $this->patchJson("/api/referencias/{$referencia->id}", ['precio_venta' => 99000, 'descripcion' => 'Regulador IM204 12V'])->assertOk();

    $historial = collect($this->getJson("/api/auditoria?tabla=referencias&registro_id={$referencia->id}")->assertOk()->json('data'))
        ->keyBy('campo');

    expect($historial)->toHaveCount(2)
        ->and($historial['precio_venta']['valor_anterior'])->toBe('85000.00')
        ->and($historial['precio_venta']['valor_nuevo'])->toBe('99000.00')
        ->and($historial['descripcion']['valor_anterior'])->toBe('Regulador IM204')
        ->and($historial['descripcion']['valor_nuevo'])->toBe('Regulador IM204 12V')
        ->and($historial['precio_venta']['usuario']['id'])->toBe($admin->id)
        ->and($historial['precio_venta']['created_at'])->not->toBeNull();
});

it('RF-11: una edición sin cambios reales no genera filas', function () {
    actuarComo(Rol::Admin);
    $referencia = referencia('RN-4410');

    $this->patchJson("/api/referencias/{$referencia->id}", ['descripcion' => $referencia->descripcion])->assertOk();

    expect(Auditoria::where('tabla', 'referencias')->count())->toBe(0);
});

it('RF-11: se auditan marcas, usuarios y el resto del catálogo, sin copiar contraseñas', function () {
    actuarComo(Rol::Admin);
    $marca = Marca::where('nombre', 'Marca Ejemplo')->first();
    $usuario = actuarComo(Rol::Mostrador);
    actuarComo(Rol::Admin);

    $this->putJson("/api/marcas/{$marca->id}", ['nombre' => 'Jota'])->assertOk();
    $this->patchJson("/api/usuarios/{$usuario->id}", ['activo' => false, 'password' => 'nueva-clave-123'])->assertOk();

    $this->assertDatabaseHas('auditoria', ['tabla' => 'marcas', 'registro_id' => $marca->id, 'campo' => 'nombre', 'valor_anterior' => 'Marca Ejemplo', 'valor_nuevo' => 'Jota']);
    $this->assertDatabaseHas('auditoria', ['tabla' => 'users', 'registro_id' => $usuario->id, 'campo' => 'activo', 'valor_anterior' => '1', 'valor_nuevo' => '0']);
    $this->assertDatabaseMissing('auditoria', ['tabla' => 'users', 'campo' => 'password']);
});

it('RF-11: las filas de auditoría no se pueden editar ni borrar', function () {
    actuarComo(Rol::Admin);
    $this->patchJson('/api/referencias/'.referencia('RN-4410')->id, ['precio_venta' => 1])->assertOk();
    $fila = Auditoria::first();

    expect(fn () => $fila->update(['valor_nuevo' => 'x']))->toThrow(RegistroInmutableException::class)
        ->and(fn () => $fila->delete())->toThrow(RegistroInmutableException::class);
});

it('RF-11: solo el admin consulta la auditoría', function () {
    actuarComo(Rol::Mostrador);

    $this->getJson('/api/auditoria')->assertForbidden();
});
