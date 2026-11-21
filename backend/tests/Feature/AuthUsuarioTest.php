<?php

use App\Enums\Rol;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/*
 * Autenticación (no es un RF): inicio de sesión con nombre de usuario en lugar de correo.
 * Los tests usan la cabecera de la SPA para que Sanctum abra sesión con cookies.
 */

const MENSAJE_GENERICO = 'Usuario o contraseña incorrectos, o usuario inactivo.';

function entrar(array $datos)
{
    return test()->withHeaders(comoSpa())->postJson('/api/login', $datos);
}

it('auth: inicia sesión con el nombre de usuario y la respuesta trae usuario, no email', function () {
    $u = User::factory()->rol(Rol::Logistica)->create(['usuario' => 'bodega']);

    entrar(['usuario' => 'bodega', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('id', $u->id)
        ->assertJsonPath('usuario', 'bodega')
        ->assertJsonMissingPath('email');

    $this->assertAuthenticatedAs($u);
});

it('auth: el usuario no distingue mayúsculas ni espacios alrededor', function () {
    $u = User::factory()->create(['usuario' => 'Mostrador.Uno']);

    expect($u->fresh()->usuario)->toBe('mostrador.uno');

    entrar(['usuario' => '  MOSTRADOR.uno ', 'password' => 'password'])->assertOk();
    $this->assertAuthenticatedAs($u);
});

it('auth: un usuario inactivo se rechaza con el mensaje genérico', function () {
    User::factory()->create(['usuario' => 'inactivo', 'activo' => false]);

    entrar(['usuario' => 'inactivo', 'password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.usuario.0', MENSAJE_GENERICO);

    $this->assertGuest();
});

it('auth: usuario inexistente y contraseña errónea dan el mismo mensaje', function () {
    User::factory()->create(['usuario' => 'caja']);

    $inexistente = entrar(['usuario' => 'nadie', 'password' => 'password'])->assertUnprocessable();
    $claveMala = entrar(['usuario' => 'caja', 'password' => 'equivocada'])->assertUnprocessable();

    expect($inexistente->json('errors'))->toBe(['usuario' => [MENSAJE_GENERICO]])
        ->and($claveMala->json('errors'))->toBe($inexistente->json('errors'));
    $this->assertGuest();
});

it('auth: un formato de usuario inválido responde 422', function (string $usuario) {
    entrar(['usuario' => $usuario, 'password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('usuario');
})->with([
    'con espacios' => 'juan perez',
    'con arroba' => 'admin@la8va.local',
    'menos de 3' => 'ab',
    'más de 30' => str_repeat('a', 31),
    'caracteres no válidos' => 'josé!',
]);

it('auth: el campo email ya no se acepta para iniciar sesión', function () {
    User::factory()->create(['usuario' => 'caja']);

    entrar(['email' => 'caja@la8va.local', 'password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['usuario', 'email']);
    $this->assertGuest();
});

it('auth: me devuelve usuario en lugar de email', function () {
    $u = actuarComo(Rol::Mostrador);

    $this->getJson('/api/me')->assertOk()->assertJsonPath('usuario', $u->usuario)->assertJsonMissingPath('email');
});

it('usuarios: el admin crea un usuario normalizado y con formato válido', function () {
    actuarComo(Rol::Admin);

    $this->postJson('/api/usuarios', ['name' => 'Ana', 'usuario' => ' Ana.Caja ', 'password' => 'clave-segura-1', 'rol' => 'mostrador'])
        ->assertCreated()
        ->assertJsonPath('usuario', 'ana.caja')
        ->assertJsonMissingPath('email');

    $this->postJson('/api/usuarios', ['name' => 'X', 'usuario' => 'con espacio', 'password' => 'clave-segura-1', 'rol' => 'mostrador'])
        ->assertUnprocessable()->assertJsonValidationErrors('usuario');
});

it('usuarios: un usuario duplicado responde 422, aunque cambien las mayúsculas', function () {
    actuarComo(Rol::Admin);
    User::factory()->create(['usuario' => 'caja']);
    $otro = User::factory()->create(['usuario' => 'bodega']);

    $this->postJson('/api/usuarios', ['name' => 'Y', 'usuario' => 'CAJA', 'password' => 'clave-segura-1', 'rol' => 'mostrador'])
        ->assertUnprocessable()->assertJsonValidationErrors('usuario');
    $this->patchJson("/api/usuarios/{$otro->id}", ['usuario' => 'caja'])
        ->assertUnprocessable()->assertJsonValidationErrors('usuario');
});

it('usuarios: el campo email ya no se acepta al crear ni al editar cuentas', function () {
    actuarComo(Rol::Admin);
    $empleado = User::factory()->create();

    $this->postJson('/api/usuarios', ['name' => 'Z', 'email' => 'z@la8va.local', 'password' => 'clave-segura-1', 'rol' => 'mostrador'])
        ->assertUnprocessable()->assertJsonValidationErrors(['usuario', 'email']);
    $this->patchJson("/api/usuarios/{$empleado->id}", ['email' => 'z@la8va.local'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->patchJson('/api/cuenta', ['email' => 'z@la8va.local'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('RF-11: cambiar el usuario de un empleado queda auditado', function () {
    $admin = actuarComo(Rol::Admin);
    $empleado = User::factory()->create(['usuario' => 'caja']);

    $this->patchJson("/api/usuarios/{$empleado->id}", ['usuario' => 'caja2'])->assertOk()->assertJsonPath('usuario', 'caja2');

    $this->assertDatabaseHas('auditoria', [
        'tabla' => 'users', 'registro_id' => $empleado->id, 'campo' => 'usuario',
        'valor_anterior' => 'caja', 'valor_nuevo' => 'caja2', 'usuario_id' => $admin->id,
    ]);
});

it('usuarios:clave funciona con el nombre de usuario, sin importar mayúsculas', function () {
    $admin = User::factory()->rol(Rol::Admin)->create(['usuario' => 'jefe']);

    $this->artisan('usuarios:clave', ['usuario' => 'JEFE'])
        ->expectsQuestion('Contraseña nueva', 'clave-servidor-2')
        ->expectsQuestion('Repita la contraseña', 'clave-servidor-2')
        ->expectsOutputToContain('jefe')
        ->assertSuccessful();

    expect(Hash::check('clave-servidor-2', $admin->fresh()->password))->toBeTrue();
});
