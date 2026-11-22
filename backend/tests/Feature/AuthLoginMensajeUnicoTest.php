<?php

use App\Enums\Rol;
use App\Models\User;

/*
 * Autenticación (no es un RF): los fallos de login no revelan si el usuario existe o está
 * inactivo, y el login tiene límite de intentos (5 por minuto por usuario + IP).
 */

function intentarLogin(string $usuario, string $password)
{
    return test()->withHeaders(comoSpa())->postJson('/api/login', ['usuario' => $usuario, 'password' => $password]);
}

it('auth: inexistente, contraseña errónea e inactivo devuelven el mismo código y el mismo cuerpo', function () {
    User::factory()->create(['usuario' => 'caja']);
    User::factory()->create(['usuario' => 'retirado', 'activo' => false]);

    $inexistente = intentarLogin('nadie', 'password');
    $claveMala = intentarLogin('caja', 'equivocada');
    $inactivo = intentarLogin('retirado', 'equivocada');

    $esperado = [
        'message' => 'Usuario o contraseña incorrectos.',
        'errors' => ['usuario' => ['Usuario o contraseña incorrectos.']],
    ];
    foreach ([$inexistente, $claveMala, $inactivo] as $r) {
        expect($r->status())->toBe(422)
            ->and($r->json())->toBe($esperado)
            ->and($r->getContent())->toBe($inexistente->getContent());
    }
    expect($inexistente->getContent())->not->toContain('inactivo');
    $this->assertGuest();
});

it('auth: un usuario activo con la contraseña correcta abre sesión Sanctum SPA', function () {
    $u = User::factory()->rol(Rol::Mostrador)->create(['usuario' => 'mostrador.dos']);

    intentarLogin('mostrador.dos', 'password')->assertOk()->assertJsonPath('id', $u->id);

    $this->assertAuthenticatedAs($u);
    $this->withHeaders(comoSpa())->getJson('/api/me')->assertOk()->assertJsonPath('usuario', 'mostrador.dos');
});

it('auth: un usuario inactivo con la contraseña correcta no entra y recibe el mismo mensaje', function () {
    User::factory()->create(['usuario' => 'caja']);
    User::factory()->create(['usuario' => 'retirado', 'activo' => false]);

    $inactivo = intentarLogin('retirado', 'password')->assertUnprocessable();
    $claveMala = intentarLogin('caja', 'equivocada');

    expect($inactivo->getContent())->toBe($claveMala->getContent());
    $this->assertGuest();
});

it('auth: al sexto intento fallido en un minuto responde 429 con el mensaje de bloqueo', function () {
    User::factory()->create(['usuario' => 'caja']);

    foreach (range(1, 5) as $i) {
        intentarLogin('caja', "mala-$i")->assertUnprocessable();
    }

    $r = intentarLogin('caja', 'mala-6')
        ->assertStatus(429)
        ->assertJsonPath('message', 'Demasiados intentos. Espere un minuto e intente de nuevo.');

    expect($r->json('retry_after'))->toBeInt()->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
    // El bloqueo es por usuario + IP: otro usuario desde la misma IP todavía puede intentar.
    intentarLogin('otro', 'mala')->assertUnprocessable();
});
