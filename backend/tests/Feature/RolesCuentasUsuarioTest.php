<?php

use App\Enums\Rol;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/** Lo que Sanctum guarda en cada sesión para detectar un cambio de contraseña. */
function huellaSesion(string $hash): string
{
    return Auth::guard('web')->hashPasswordForCookie($hash);
}

it('roles: el admin edita a un empleado, incluida su contraseña y su rol entre logística y mostrador', function () {
    actuarComo(Rol::Admin);
    $empleado = User::factory()->rol(Rol::Mostrador)->create();

    $this->patchJson("/api/usuarios/{$empleado->id}", [
        'name' => 'Nuevo nombre',
        'rol' => 'logistica',
        'password' => 'clave-nueva-123',
        'password_confirmation' => 'clave-nueva-123',
    ])->assertOk();

    $empleado->refresh();
    expect($empleado->name)->toBe('Nuevo nombre')
        ->and($empleado->rol)->toBe(Rol::Logistica)
        ->and(Hash::check('clave-nueva-123', $empleado->password))->toBeTrue();
});

it('roles: la contraseña de un empleado se confirma', function () {
    actuarComo(Rol::Admin);
    $empleado = User::factory()->rol(Rol::Mostrador)->create();

    $this->patchJson("/api/usuarios/{$empleado->id}", ['password' => 'clave-nueva-123', 'password_confirmation' => 'otra-123456'])
        ->assertUnprocessable()->assertJsonValidationErrors('password');
});

it('roles: un admin no edita a otro admin en nada', function () {
    actuarComo(Rol::Admin);
    $otro = User::factory()->rol(Rol::Admin)->create(['name' => 'Otro admin']);

    foreach ([['name' => 'x'], ['password' => 'clave-nueva-123', 'password_confirmation' => 'clave-nueva-123'], ['rol' => 'mostrador'], ['activo' => false]] as $cambio) {
        $this->patchJson("/api/usuarios/{$otro->id}", $cambio)->assertForbidden();
    }

    $otro->refresh();
    expect($otro->name)->toBe('Otro admin')
        ->and($otro->rol)->toBe(Rol::Admin)
        ->and($otro->activo)->toBeTrue()
        ->and(Hash::check('password', $otro->password))->toBeTrue();
});

it('roles: el admin no edita su propia fila desde usuarios (ni rol ni estado)', function () {
    $admin = actuarComo(Rol::Admin);

    $this->patchJson("/api/usuarios/{$admin->id}", ['activo' => false])->assertForbidden();
    $this->patchJson("/api/usuarios/{$admin->id}", ['rol' => 'mostrador'])->assertForbidden();

    expect($admin->fresh()->rol)->toBe(Rol::Admin)->and($admin->fresh()->activo)->toBeTrue();
});

it('roles: un empleado no se asciende a admin, el admin nuevo se crea como cuenta nueva', function () {
    actuarComo(Rol::Admin);
    $empleado = User::factory()->rol(Rol::Logistica)->create();

    $this->patchJson("/api/usuarios/{$empleado->id}", ['rol' => 'admin'])->assertUnprocessable();
    expect($empleado->fresh()->rol)->toBe(Rol::Logistica);

    $this->postJson('/api/usuarios', ['name' => 'Admin nuevo', 'usuario' => 'admin.nuevo', 'password' => 'clave-nueva-123', 'rol' => 'admin'])
        ->assertCreated()->assertJsonPath('rol', 'admin');
});

it('roles: cambiar la contraseña de un empleado cierra sus sesiones abiertas', function () {
    actuarComo(Rol::Admin);
    $empleado = User::factory()->rol(Rol::Mostrador)->create();
    $hashViejo = $empleado->password;

    $this->patchJson("/api/usuarios/{$empleado->id}", ['password' => 'clave-nueva-123', 'password_confirmation' => 'clave-nueva-123'])->assertOk();

    $this->actingAs($empleado->fresh())
        ->withSession(['password_hash_web' => huellaSesion($hashViejo)])
        ->withHeaders(comoSpa())
        ->getJson('/api/me')
        ->assertUnauthorized();

    $this->actingAs($empleado->fresh())
        ->withSession(['password_hash_web' => huellaSesion($empleado->fresh()->password)])
        ->withHeaders(comoSpa())
        ->getJson('/api/me')
        ->assertOk();
});

it('cuenta: el admin cambia su nombre sin contraseña', function () {
    $admin = actuarComo(Rol::Admin);

    $this->patchJson('/api/cuenta', ['name' => 'Admin la 8va'])->assertOk()->assertJsonPath('name', 'Admin la 8va');

    expect($admin->fresh()->name)->toBe('Admin la 8va');
});

it('cuenta: cambiar el usuario o la contraseña pide la contraseña actual', function () {
    $admin = actuarComo(Rol::Admin);

    $this->patchJson('/api/cuenta', ['usuario' => 'otro.admin'])
        ->assertUnprocessable()->assertJsonValidationErrors('password_actual');
    $this->patchJson('/api/cuenta', ['usuario' => 'otro.admin', 'password_actual' => 'equivocada'])
        ->assertUnprocessable()->assertJsonValidationErrors('password_actual');
    $this->patchJson('/api/cuenta', ['password' => 'clave-nueva-123', 'password_confirmation' => 'clave-nueva-123'])
        ->assertUnprocessable()->assertJsonValidationErrors('password_actual');

    expect($admin->fresh()->usuario)->not->toBe('otro.admin');

    $this->patchJson('/api/cuenta', ['usuario' => 'otro.admin', 'password_actual' => 'password'])->assertOk();
    expect($admin->fresh()->usuario)->toBe('otro.admin');
});

it('cuenta: la contraseña nueva se confirma y reemplaza a la anterior', function () {
    $admin = actuarComo(Rol::Admin);

    $this->patchJson('/api/cuenta', ['password_actual' => 'password', 'password' => 'clave-nueva-123', 'password_confirmation' => 'otra-123456'])
        ->assertUnprocessable()->assertJsonValidationErrors('password');

    $this->patchJson('/api/cuenta', ['password_actual' => 'password', 'password' => 'clave-nueva-123', 'password_confirmation' => 'clave-nueva-123'])
        ->assertOk();

    expect(Hash::check('clave-nueva-123', $admin->fresh()->password))->toBeTrue();
});

it('cuenta: al cambiar la contraseña la sesión actual sigue y las otras se cierran', function () {
    $admin = actuarComo(Rol::Admin);
    $hashViejo = $admin->password;

    $respuesta = $this->withHeaders(comoSpa())
        ->withSession(['password_hash_web' => huellaSesion($hashViejo)])
        ->patchJson('/api/cuenta', ['password_actual' => 'password', 'password' => 'clave-nueva-123', 'password_confirmation' => 'clave-nueva-123'])
        ->assertOk();

    $respuesta->assertSessionHas('password_hash_web', huellaSesion($admin->fresh()->password));

    $this->actingAs($admin->fresh())
        ->withSession(['password_hash_web' => huellaSesion($hashViejo)])
        ->withHeaders(comoSpa())
        ->getJson('/api/me')
        ->assertUnauthorized();
});

it('cuenta: desde su cuenta el admin no cambia su rol ni su estado', function () {
    $admin = actuarComo(Rol::Admin);

    $this->patchJson('/api/cuenta', ['name' => 'Admin', 'rol' => 'mostrador', 'activo' => false])->assertOk();

    expect($admin->fresh()->rol)->toBe(Rol::Admin)->and($admin->fresh()->activo)->toBeTrue();
});

it('cuenta: logística y mostrador no editan su cuenta', function (Rol $rol) {
    actuarComo($rol);

    $this->patchJson('/api/cuenta', ['name' => 'x'])->assertForbidden();
})->with([Rol::Logistica, Rol::Mostrador]);

it('RF-11: el cambio de contraseña queda auditado sin copiar la clave ni su hash', function () {
    $admin = actuarComo(Rol::Admin);
    $empleado = User::factory()->rol(Rol::Mostrador)->create();
    $hashViejo = $empleado->password;

    $this->patchJson("/api/usuarios/{$empleado->id}", ['password' => 'clave-nueva-123', 'password_confirmation' => 'clave-nueva-123'])->assertOk();

    $this->assertDatabaseHas('auditoria', [
        'tabla' => 'users', 'registro_id' => $empleado->id, 'campo' => 'password',
        'valor_anterior' => '••••', 'valor_nuevo' => '••••', 'usuario_id' => $admin->id,
    ]);
    $this->assertDatabaseMissing('auditoria', ['valor_anterior' => $hashViejo]);
    $this->assertDatabaseMissing('auditoria', ['valor_nuevo' => $empleado->fresh()->password]);
});

it('usuarios:clave restablece la contraseña de un admin desde el servidor', function () {
    $admin = User::factory()->rol(Rol::Admin)->create(['usuario' => 'jefe']);

    $this->artisan('usuarios:clave', ['usuario' => 'jefe'])
        ->expectsQuestion('Contraseña nueva', 'clave-servidor-1')
        ->expectsQuestion('Repita la contraseña', 'clave-servidor-1')
        ->assertSuccessful();

    expect(Hash::check('clave-servidor-1', $admin->fresh()->password))->toBeTrue();
});

it('usuarios:clave falla con un usuario inexistente o si la confirmación no coincide', function () {
    User::factory()->rol(Rol::Admin)->create(['usuario' => 'jefe']);

    $this->artisan('usuarios:clave', ['usuario' => 'nadie'])->assertFailed();

    $this->artisan('usuarios:clave', ['usuario' => 'jefe'])
        ->expectsQuestion('Contraseña nueva', 'clave-servidor-1')
        ->expectsQuestion('Repita la contraseña', 'otra-clave-22')
        ->assertFailed();
});
