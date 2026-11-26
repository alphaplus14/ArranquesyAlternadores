<?php

use App\Enums\Rol;
use App\Models\Auditoria;
use App\Models\User;

/*
 * S-70: preferencias de pantalla por usuario (admin, logística y contable). Se guardan solo las
 * cambiadas, null borra la clave, viajan en /me y no se auditan.
 */

it('S-70: guarda las preferencias (merge) y /me las trae', function () {
    $u = actuarComo(Rol::Logistica);

    $this->patchJson('/api/me/preferencias', ['tamano_letra' => 'grande', 'acento' => 'verde'])
        ->assertOk()
        ->assertExactJson(['preferencias' => ['acento' => 'verde', 'tamano_letra' => 'grande']]);
    $this->patchJson('/api/me/preferencias', ['filas_por_pagina' => 50, 'menu_plegado' => true])
        ->assertOk()
        ->assertJsonPath('preferencias.acento', 'verde')
        ->assertJsonPath('preferencias.filas_por_pagina', 50)
        ->assertJsonPath('preferencias.menu_plegado', true);

    $this->getJson('/api/me')->assertOk()
        ->assertJsonPath('preferencias', ['acento' => 'verde', 'filas_por_pagina' => 50, 'menu_plegado' => true, 'tamano_letra' => 'grande']);
    expect($u->fresh()->preferencias)->toHaveCount(4);
});

it('S-70: sin preferencias /me devuelve un objeto vacío; también el login', function () {
    actuarComo(Rol::Admin);
    expect($this->getJson('/api/me')->assertOk()->getContent())->toContain('"preferencias":{}');
});

it('S-70: null borra la clave y vuelve al valor por defecto', function () {
    $u = actuarComo(Rol::Contable);
    $this->patchJson('/api/me/preferencias', ['densidad' => 'compacta', 'acento' => 'vino'])->assertOk();

    $this->patchJson('/api/me/preferencias', ['densidad' => null])->assertOk()->assertExactJson(['preferencias' => ['acento' => 'vino']]);
    $this->patchJson('/api/me/preferencias', ['acento' => null])->assertOk();
    expect($u->fresh()->preferencias)->toBeNull();
    expect($this->getJson('/api/me')->getContent())->toContain('"preferencias":{}');
});

it('S-70: valores fuera de la lista y claves desconocidas son 422', function () {
    actuarComo(Rol::Admin);

    $this->patchJson('/api/me/preferencias', ['tamano_letra' => 'enorme'])->assertUnprocessable()->assertJsonValidationErrors('tamano_letra');
    $this->patchJson('/api/me/preferencias', ['densidad' => 'apretada'])->assertUnprocessable()->assertJsonValidationErrors('densidad');
    $this->patchJson('/api/me/preferencias', ['acento' => '#ff0000'])->assertUnprocessable()->assertJsonValidationErrors('acento');
    $this->patchJson('/api/me/preferencias', ['filas_por_pagina' => 30])->assertUnprocessable()->assertJsonValidationErrors('filas_por_pagina');
    $this->patchJson('/api/me/preferencias', ['menu_plegado' => 'tal vez'])->assertUnprocessable()->assertJsonValidationErrors('menu_plegado');
    $this->patchJson('/api/me/preferencias', ['tema' => 'oscuro'])->assertUnprocessable()->assertJsonValidationErrors('tema');
    $this->patchJson('/api/me/preferencias', ['acento' => 'verde', 'fuente' => 'Arial'])->assertUnprocessable()->assertJsonValidationErrors('fuente');
});

it('S-70: el mostrador recibe 403 y su /me trae preferencias vacías', function () {
    $u = actuarComo(Rol::Mostrador);
    $this->patchJson('/api/me/preferencias', ['acento' => 'verde'])->assertForbidden();

    // Aunque hubiera algo guardado, a mostrador no se le envía.
    $u->forceFill(['preferencias' => ['acento' => 'verde']])->save();
    expect($this->getJson('/api/me')->assertOk()->getContent())->toContain('"preferencias":{}');
});

it('S-70: las preferencias no crean filas en la auditoría (ni al guardar ni al borrar)', function () {
    $u = actuarComo(Rol::Admin);
    $antes = Auditoria::count();

    $this->patchJson('/api/me/preferencias', ['acento' => 'morado', 'filas_por_pagina' => 100])->assertOk();
    $this->patchJson('/api/me/preferencias', ['acento' => null])->assertOk();

    expect(Auditoria::count())->toBe($antes);
    expect(Auditoria::where('tabla', 'users')->where('campo', 'preferencias')->exists())->toBeFalse();
});

it('S-70: Auditable excluye la columna preferencias pero sigue auditando los demás campos del usuario', function () {
    actuarComo(Rol::Admin);
    $otro = User::factory()->rol(Rol::Logistica)->create(['name' => 'Ana']);

    $otro->update(['name' => 'Ana María', 'preferencias' => ['acento' => 'verde']]);

    expect(Auditoria::where('tabla', 'users')->where('registro_id', $otro->id)->pluck('campo')->all())->toBe(['name']);
});

it('S-70: los listados aceptan por_pagina con tope 100 y conservan su valor por defecto', function () {
    actuarComo(Rol::Admin);

    expect($this->getJson('/api/ingresos')->assertOk()->json('per_page'))->toBe(25)
        ->and($this->getJson('/api/ingresos?por_pagina=50')->json('per_page'))->toBe(50)
        ->and($this->getJson('/api/ingresos?por_pagina=500')->json('per_page'))->toBe(100)
        ->and($this->getJson('/api/usuarios')->json('per_page'))->toBe(50)
        ->and($this->getJson('/api/usuarios?por_pagina=25')->json('per_page'))->toBe(25)
        ->and($this->getJson('/api/auditoria')->json('per_page'))->toBe(50)
        ->and($this->getJson('/api/auditoria?por_pagina=100')->json('per_page'))->toBe(100)
        ->and($this->getJson('/api/ajustes')->json('meta.per_page'))->toBe(20)
        ->and($this->getJson('/api/ajustes?por_pagina=100')->json('meta.per_page'))->toBe(100)
        ->and($this->getJson('/api/ingresos/correcciones?por_pagina=50')->json('meta.per_page'))->toBe(50);
    $this->getJson('/api/ajustes?por_pagina=500')->assertUnprocessable();
});
