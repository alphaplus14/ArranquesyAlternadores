<?php

use App\Enums\Rol;
use App\Exceptions\RegistroInmutableException;
use App\Models\Auditoria;
use App\Models\Proveedor;
use App\Models\Referencia;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => sembrarDemo());

it('RF-11: cambiar precio y descripción de una referencia deja el antes, el después, quién y cuándo', function () {
    $admin = actuarComo(Rol::Admin);
    $referencia = referencia('RN-4410');

    $this->patchJson("/api/referencias/{$referencia->id}", ['precio_venta' => 99000, 'descripcion' => 'Regulador IM204 12V'])->assertOk();

    // S-26: la fila de creación (campo "*") también está en el historial; aquí cuentan las ediciones.
    $historial = collect($this->getJson("/api/auditoria?tabla=referencias&registro_id={$referencia->id}")->assertOk()->json('data'))
        ->keyBy('campo')->except('*');

    expect($historial)->toHaveCount(2)
        ->and($historial['precio_venta']['valor_anterior'])->toBe('85000.00')
        ->and($historial['precio_venta']['valor_nuevo'])->toBe('99000.00')
        ->and($historial['descripcion']['valor_anterior'])->toBe('REGULADOR IM204')
        ->and($historial['descripcion']['valor_nuevo'])->toBe('REGULADOR IM204 12V') // S-85: descripción en mayúscula
        ->and($historial['precio_venta']['usuario']['id'])->toBe($admin->id)
        ->and($historial['precio_venta']['created_at'])->not->toBeNull();
});

it('RF-11: una edición sin cambios reales no genera filas', function () {
    actuarComo(Rol::Admin);
    $referencia = referencia('RN-4410');
    // S-85: la descripción se guarda en mayúscula; se parte de una ya en mayúscula para que el mismo valor no cambie.
    DB::table('referencias')->where('id', $referencia->id)->update(['descripcion' => mb_strtoupper($referencia->descripcion)]);
    $referencia = $referencia->fresh();

    $this->patchJson("/api/referencias/{$referencia->id}", ['descripcion' => $referencia->descripcion])->assertOk();

    // S-26: las creaciones (campo "*") sí se auditan; una edición sin cambios no agrega filas.
    expect(Auditoria::where('tabla', 'referencias')->where('campo', '!=', '*')->count())->toBe(0);
});

it('RF-11: se auditan proveedores, usuarios y el resto del catálogo, sin copiar contraseñas', function () {
    actuarComo(Rol::Admin);
    $proveedor = Proveedor::where('nombre', 'Autopartes Central')->first();
    $usuario = actuarComo(Rol::Mostrador);
    actuarComo(Rol::Admin);

    $this->putJson("/api/proveedores/{$proveedor->id}", ['nombre' => 'Autopartes Central SAS'])->assertOk();
    $this->patchJson("/api/usuarios/{$usuario->id}", ['activo' => false, 'password' => 'nueva-clave-123', 'password_confirmation' => 'nueva-clave-123'])->assertOk();

    $this->assertDatabaseHas('auditoria', ['tabla' => 'proveedores', 'registro_id' => $proveedor->id, 'campo' => 'nombre', 'valor_anterior' => 'Autopartes Central', 'valor_nuevo' => 'Autopartes Central SAS']);
    $this->assertDatabaseHas('auditoria', ['tabla' => 'users', 'registro_id' => $usuario->id, 'campo' => 'activo', 'valor_anterior' => '1', 'valor_nuevo' => '0']);
    $this->assertDatabaseHas('auditoria', ['tabla' => 'users', 'campo' => 'password', 'valor_anterior' => '••••', 'valor_nuevo' => '••••']);
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

it('RF-11: el historial resuelve los ids a nombres en lote, sin tocar valor anterior ni nuevo', function () {
    actuarComo(Rol::Admin);
    $base = referencia('RN-4410');
    $nueva = Referencia::create([
        'codigo_referencia' => 'AUD-001', 'proveedor_id' => $base->proveedor_id, 'categoria_id' => $base->categoria_id,
        'descripcion' => 'Regulador de prueba', 'precio_venta' => 50000,
    ]);
    $confirmador = compra($base, 1, 40000)->fresh()->confirmado_por; // confirmado_por, total, estado… del ingreso.

    DB::enableQueryLog();
    $respuesta = $this->getJson('/api/auditoria')->assertOk();
    $consultas = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    // valor_anterior y valor_nuevo llegan tal como están guardados.
    $creacion = collect($respuesta->json('data'))->first(fn ($f) => $f['tabla'] === 'referencias' && $f['registro_id'] === $nueva->id && $f['campo'] === '*');
    $guardada = Auditoria::where('tabla', 'referencias')->where('registro_id', $nueva->id)->where('campo', '*')->first();
    expect($creacion['valor_nuevo'])->toBe($guardada->valor_nuevo)
        ->and($creacion['valor_anterior'])->toBeNull();

    // Nombres de apoyo: los ids de la foto JSON y de los campos sueltos.
    $nombres = $respuesta->json('nombres');
    expect($nombres['proveedor_id'][(string) $base->proveedor_id])->toBe($base->proveedor->nombre)
        ->and($nombres['categoria_id'][(string) $base->categoria_id])->toBe($base->categoria->nombre)
        ->and($nombres['confirmado_por'][(string) $confirmador])->toBe(User::find($confirmador)->name);

    // En lote: una sola consulta a proveedores, a categorías y a usuarios para los nombres
    // (más la del eager loading de usuario), sin importar cuántas filas tenga la página.
    $a = fn (string $tabla) => $consultas->filter(fn ($q) => str_contains($q, "from `{$tabla}`"))->count();
    expect($a('proveedores'))->toBe(1)
        ->and($a('categorias'))->toBe(1)
        ->and($a('users'))->toBeLessThanOrEqual(2);
});
