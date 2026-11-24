<?php

use App\Enums\Rol;

it('un ingreso que no existe responde en español, sin el nombre interno del modelo', function () {
    actuarComo(Rol::Logistica);

    $this->getJson('/api/ingresos/7892684001502/unidades')
        ->assertNotFound()
        ->assertJsonPath('message', 'No se encontró el ingreso.')
        ->assertJsonMissingPath('exception')
        ->assertJsonMissingPath('trace');
});

it('una venta que no existe responde en español', function () {
    actuarComo(Rol::Mostrador);

    $this->getJson('/api/ventas/7892684001502')
        ->assertNotFound()
        ->assertJsonPath('message', 'No se encontró la venta.');
});

it('una ruta que no existe no revela la dirección pedida', function () {
    actuarComo(Rol::Admin);

    $this->getJson('/api/marcas')
        ->assertNotFound()
        ->assertJsonPath('message', 'No se encontró lo que buscaba.');
});

it('un método que la ruta no acepta no revela la dirección', function () {
    actuarComo(Rol::Admin);

    $this->postJson('/api/auditoria')
        ->assertStatus(405)
        ->assertJsonPath('message', 'Esa acción no está disponible.');
});

it('sin sesión la API pide iniciar sesión, sin el texto Unauthenticated', function () {
    $this->getJson('/api/me')
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Debe iniciar sesión.');
});
