<?php

use App\Enums\Rol;
use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\CodigoUniversal;
use App\Models\Referencia;

/*
 * S-59: logística crea y edita códigos universales; borrarlos sigue siendo del admin. Se conservan
 * las validaciones de RF-01 (categoría que admite universal, código único, sin referencias de otra
 * categoría). Cada cambio queda auditado (RF-11).
 */

function s59Categoria(bool $admite = true): Categoria
{
    return Categoria::factory()->create(['admite_codigo_universal' => $admite]);
}

it('S-59: logística crea (201) y edita (200) un código universal, auditado', function () {
    $usuario = actuarComo(Rol::Logistica);
    $cat = s59Categoria();

    $id = $this->postJson('/api/codigos-universales', ['codigo' => 'IM204', 'categoria_id' => $cat->id, 'descripcion' => 'Regulador'])
        ->assertCreated()->json('id');
    $this->putJson("/api/codigos-universales/{$id}", ['descripcion' => 'Regulador 12V'])->assertOk()->assertJsonPath('descripcion', 'Regulador 12V');

    expect(Auditoria::where('tabla', 'codigos_universales')->where('registro_id', $id)->where('campo', 'descripcion')->value('usuario_id'))->toBe($usuario->id);
});

it('S-59: logística no borra universales; el admin sí', function () {
    $u = CodigoUniversal::factory()->create(['categoria_id' => s59Categoria()->id]);

    actuarComo(Rol::Logistica);
    $this->deleteJson("/api/codigos-universales/{$u->id}")->assertForbidden();

    actuarComo(Rol::Admin);
    $this->deleteJson("/api/codigos-universales/{$u->id}")->assertNoContent();
});

it('S-59: el mostrador recibe 403 al crear o editar universales', function () {
    $u = CodigoUniversal::factory()->create(['categoria_id' => s59Categoria()->id]);
    actuarComo(Rol::Mostrador);

    $this->postJson('/api/codigos-universales', ['codigo' => 'IM999', 'categoria_id' => s59Categoria()->id])->assertForbidden();
    $this->putJson("/api/codigos-universales/{$u->id}", ['descripcion' => 'x'])->assertForbidden();
});

it('S-59 / RF-01: categoría sin universal rechazada y código único', function () {
    actuarComo(Rol::Logistica);
    $cat = s59Categoria();

    $this->postJson('/api/codigos-universales', ['codigo' => 'IM300', 'categoria_id' => s59Categoria(false)->id])
        ->assertUnprocessable()->assertJsonValidationErrors('categoria_id');
    $this->postJson('/api/codigos-universales', ['codigo' => 'IM300', 'categoria_id' => $cat->id])->assertCreated();
    $this->postJson('/api/codigos-universales', ['codigo' => 'IM300', 'categoria_id' => $cat->id])
        ->assertUnprocessable()->assertJsonValidationErrors('codigo');
});

it('S-59 / RF-01: un universal no queda con referencias de otra categoría', function () {
    actuarComo(Rol::Logistica);
    $cat = s59Categoria();
    $u = CodigoUniversal::factory()->create(['categoria_id' => $cat->id]);
    Referencia::factory()->create(['categoria_id' => $cat->id, 'codigo_universal_id' => $u->id]);

    $this->putJson("/api/codigos-universales/{$u->id}", ['categoria_id' => s59Categoria()->id])
        ->assertUnprocessable()->assertJsonValidationErrors('categoria_id');
});
