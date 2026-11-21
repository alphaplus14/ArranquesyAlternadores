<?php

use App\Enums\Rol;
use App\Models\Auditoria;
use App\Models\Cliente;

it('RF-13: crear un cliente con plazo por defecto de 30 días (S-11)', function () {
    actuarComo(Rol::Admin);

    $this->postJson('/api/clientes', ['nombre' => 'Taller El Pistón', 'documento' => '900123456', 'telefono' => '3001234567'])
        ->assertCreated()
        ->assertJsonPath('nombre', 'Taller El Pistón')
        ->assertJsonPath('plazo_dias', 30)
        ->assertJsonPath('activo', true);

    $this->assertDatabaseHas('clientes', ['documento' => '900123456', 'plazo_dias' => 30]);
});

it('RF-13: el documento es único', function () {
    actuarComo(Rol::Admin);
    Cliente::factory()->create(['documento' => '123']);

    $this->postJson('/api/clientes', ['nombre' => 'Otro', 'documento' => '123'])->assertUnprocessable();
});

it('RF-13: cambiar plazo_dias deja en auditoría valor anterior, valor nuevo, usuario y fecha', function () {
    $admin = actuarComo(Rol::Admin);
    $cliente = Cliente::factory()->create(['plazo_dias' => 30]);

    $this->patchJson("/api/clientes/{$cliente->id}", ['plazo_dias' => 45])->assertOk()->assertJsonPath('plazo_dias', 45);

    $fila = Auditoria::where('tabla', 'clientes')->where('registro_id', $cliente->id)->sole();
    expect($fila->campo)->toBe('plazo_dias')
        ->and($fila->valor_anterior)->toBe('30')
        ->and($fila->valor_nuevo)->toBe('45')
        ->and($fila->usuario_id)->toBe($admin->id)
        ->and($fila->created_at)->not->toBeNull();
});

it('RF-13: mostrador puede crear pero no editar', function () {
    actuarComo(Rol::Mostrador);

    $id = $this->postJson('/api/clientes', ['nombre' => 'Cliente de mostrador'])->assertCreated()->json('id');

    $this->patchJson("/api/clientes/{$id}", ['plazo_dias' => 60])->assertForbidden();
    expect(Cliente::find($id)->plazo_dias)->toBe(30);
});

it('RF-13: logística lista pero no crea; no hay ruta DELETE', function () {
    actuarComo(Rol::Logistica);
    $cliente = Cliente::factory()->create();

    $this->getJson('/api/clientes')->assertOk()->assertJsonPath('data.0.id', $cliente->id);
    $this->postJson('/api/clientes', ['nombre' => 'X'])->assertForbidden();

    actuarComo(Rol::Admin);
    $this->deleteJson("/api/clientes/{$cliente->id}")->assertStatus(405);
});

it('RF-13: el listado se pagina en el servidor y busca por nombre o documento', function () {
    actuarComo(Rol::Mostrador);
    Cliente::factory()->count(30)->create();
    Cliente::factory()->create(['nombre' => 'Zuluaga Repuestos', 'documento' => '800999']);

    $this->getJson('/api/clientes')->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.total', 31);
    $this->getJson('/api/clientes?q=Zulu')->assertJsonCount(1, 'data');
    $this->getJson('/api/clientes?q=800999')->assertJsonPath('data.0.nombre', 'Zuluaga Repuestos');
});
