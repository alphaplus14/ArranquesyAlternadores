<?php

use App\Enums\Rol;
use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Proveedor;

/*
 * S-23 (RF-01 / RF-11): la referencia tiene una calidad opcional, fino o genérico, auditable
 * como el resto de sus campos.
 */

beforeEach(fn () => sembrarDemo());

/** Datos válidos de una referencia nueva. */
function referenciaConCalidad(array $extra = []): array
{
    return [
        'codigo_referencia' => 'CAL-'.uniqid(),
        'marca_id' => Marca::value('id'),
        'proveedor_id' => Proveedor::value('id'),
        'categoria_id' => Categoria::where('admite_codigo_universal', false)->value('id'),
        'descripcion' => 'Pieza de prueba',
        'precio_venta' => 1000,
        ...$extra,
    ];
}

it('S-23: crear una referencia acepta calidad fino, generico o ninguna', function (?string $calidad) {
    actuarComo(Rol::Admin);

    $this->postJson('/api/referencias', referenciaConCalidad(['calidad' => $calidad]))
        ->assertCreated()
        ->assertJsonPath('calidad', $calidad);
})->with(['fino', 'generico', null]);

it('S-23: la calidad es opcional al crear', function () {
    actuarComo(Rol::Admin);

    $this->postJson('/api/referencias', referenciaConCalidad())->assertCreated()->assertJsonPath('calidad', null);
});

it('S-23: crear o editar con una calidad distinta de fino o generico responde 422', function (string $calidad) {
    actuarComo(Rol::Admin);
    $referencia = referencia('RN-4410');

    $this->postJson('/api/referencias', referenciaConCalidad(['calidad' => $calidad]))
        ->assertUnprocessable()->assertJsonValidationErrors('calidad');
    $this->patchJson("/api/referencias/{$referencia->id}", ['calidad' => $calidad])
        ->assertUnprocessable()->assertJsonValidationErrors('calidad');

    expect($referencia->fresh()->calidad)->toBeNull();
})->with(['premium', 'Fino', 'genérico']);

it('S-23: cambiar la calidad de una referencia queda en auditoría', function () {
    $admin = actuarComo(Rol::Admin);
    $referencia = referencia('RN-4410');

    $this->patchJson("/api/referencias/{$referencia->id}", ['calidad' => 'fino'])->assertOk()->assertJsonPath('calidad', 'fino');
    $this->patchJson("/api/referencias/{$referencia->id}", ['calidad' => 'generico'])->assertOk();

    $this->assertDatabaseHas('auditoria', ['tabla' => 'referencias', 'registro_id' => $referencia->id, 'campo' => 'calidad', 'valor_anterior' => null, 'valor_nuevo' => 'fino', 'usuario_id' => $admin->id]);
    $this->assertDatabaseHas('auditoria', ['tabla' => 'referencias', 'registro_id' => $referencia->id, 'campo' => 'calidad', 'valor_anterior' => 'fino', 'valor_nuevo' => 'generico']);
});
