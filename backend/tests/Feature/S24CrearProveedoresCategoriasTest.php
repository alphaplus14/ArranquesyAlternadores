<?php

use App\Enums\Rol;
use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Proveedor;

/*
 * S-24 (RF-04, RF-20, regla 12): logística y admin crean proveedores y categorías; editar y
 * borrar sigue siendo del admin; mostrador no crea nada de esto. Toda creación se audita (RF-11).
 */

beforeEach(fn () => sembrarDemo());

it('S-24 (a): logística y admin crean un proveedor; mostrador recibe 403', function () {
    actuarComo(Rol::Logistica);
    $this->postJson('/api/proveedores', ['nombre' => 'Eléctricos Ochoa', 'nit' => ' 900.123.456-7 ', 'plazo_dias' => 45])
        ->assertCreated()
        ->assertExactJson(['id' => Proveedor::where('nombre', 'Eléctricos Ochoa')->value('id'), 'nombre' => 'Eléctricos Ochoa', 'nit' => '900.123.456-7', 'plazo_dias' => 45]);

    actuarComo(Rol::Admin);
    $this->postJson('/api/proveedores', ['nombre' => 'Baterías del Sur'])->assertCreated();

    actuarComo(Rol::Mostrador);
    $this->postJson('/api/proveedores', ['nombre' => 'No debe existir'])->assertForbidden();
    expect(Proveedor::where('nombre', 'No debe existir')->exists())->toBeFalse();
});

it('S-24 (b): logística no puede editar ni borrar un proveedor', function () {
    actuarComo(Rol::Logistica);
    $proveedor = Proveedor::where('nombre', 'Autopartes Central')->first();

    $this->patchJson("/api/proveedores/{$proveedor->id}", ['nombre' => 'Otro nombre'])->assertForbidden();
    $this->deleteJson("/api/proveedores/{$proveedor->id}")->assertForbidden();

    expect($proveedor->fresh()->nombre)->toBe('Autopartes Central');
});

it('S-24 (c): un nombre de proveedor repetido con otras mayúsculas o tildes responde 422', function (string $nombre) {
    actuarComo(Rol::Logistica);
    $existente = Proveedor::where('nombre', 'Distribuidora Eléctrica')->first();

    $this->postJson('/api/proveedores', ['nombre' => $nombre])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('nombre')
        ->assertJsonPath('proveedor_existente_id', $existente->id);
})->with(['DISTRIBUIDORA ELECTRICA', 'distribuidora eléctrica', '  Distribuidora Electrica ']);

it('S-24 (d): un NIT repetido en otra forma responde 422 con proveedor_existente_id', function (string $nit) {
    actuarComo(Rol::Logistica);
    $existente = Proveedor::create(['nombre' => 'Repuestos Ochoa', 'nit' => '900.123.456-7']);

    $this->postJson('/api/proveedores', ['nombre' => 'Otro proveedor', 'nit' => $nit])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('nit')
        ->assertJsonPath('proveedor_existente_id', $existente->id)
        ->assertJsonPath('proveedor_existente', 'Repuestos Ochoa');
})->with(['9001234567', '900 123 456 7', '900-123-456-7']);

it('S-24 (e): plazo fuera de 0 a 365 responde 422; sin NIT ni plazo se crea', function (mixed $plazo) {
    actuarComo(Rol::Logistica);

    $this->postJson('/api/proveedores', ['nombre' => 'Con plazo raro', 'plazo_dias' => $plazo])
        ->assertUnprocessable()->assertJsonValidationErrors('plazo_dias');
    $this->postJson('/api/proveedores', ['nombre' => 'Solo nombre'])
        ->assertCreated()->assertJsonPath('nit', null)->assertJsonPath('plazo_dias', null);
})->with([-1, 366, 2.5]);

it('S-24 (f): logística y admin crean una categoría; mostrador recibe 403', function () {
    actuarComo(Rol::Logistica);
    $this->postJson('/api/categorias', ['nombre' => 'Bobinas'])->assertCreated()->assertJsonPath('nombre', 'Bobinas');

    actuarComo(Rol::Admin);
    $this->postJson('/api/categorias', ['nombre' => 'Rectificadores', 'admite_codigo_universal' => true])
        ->assertCreated()->assertJsonPath('admite_codigo_universal', true);

    actuarComo(Rol::Mostrador);
    $this->postJson('/api/categorias', ['nombre' => 'No debe existir'])->assertForbidden();
});

it('S-24 (g): una categoría creada por logística queda sin código universal aunque lo envíe', function () {
    actuarComo(Rol::Logistica);

    $this->postJson('/api/categorias', ['nombre' => 'Inducidos', 'admite_codigo_universal' => true])
        ->assertCreated()->assertJsonPath('admite_codigo_universal', false);

    expect(Categoria::where('nombre', 'Inducidos')->value('admite_codigo_universal'))->toBeFalse();
});

it('S-24 (h): logística no puede editar ni borrar una categoría; el admin sí', function () {
    $categoria = Categoria::create(['nombre' => 'Temporal']);

    actuarComo(Rol::Logistica);
    $this->patchJson("/api/categorias/{$categoria->id}", ['admite_codigo_universal' => true])->assertForbidden();
    $this->deleteJson("/api/categorias/{$categoria->id}")->assertForbidden();

    actuarComo(Rol::Admin);
    $this->patchJson("/api/categorias/{$categoria->id}", ['admite_codigo_universal' => true])
        ->assertOk()->assertJsonPath('admite_codigo_universal', true);
    $this->deleteJson("/api/categorias/{$categoria->id}")->assertNoContent();
});

it('S-24 (i): un nombre de categoría repetido responde 422 con la categoría existente', function () {
    actuarComo(Rol::Logistica);
    $sensores = Categoria::where('nombre', 'Sensores')->first();

    $this->postJson('/api/categorias', ['nombre' => 'SENSORES'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('nombre')
        ->assertJsonPath('categoria_existente_id', $sensores->id);
});

it('S-24 (j): crear un proveedor y una categoría queda en la auditoría', function () {
    $logistica = actuarComo(Rol::Logistica);

    $proveedorId = $this->postJson('/api/proveedores', ['nombre' => 'Auditado SAS', 'nit' => '800-1'])->assertCreated()->json('id');
    $categoriaId = $this->postJson('/api/categorias', ['nombre' => 'Auditada'])->assertCreated()->json('id');

    $filaProveedor = Auditoria::where(['tabla' => 'proveedores', 'registro_id' => $proveedorId, 'campo' => '*'])->first();
    $filaCategoria = Auditoria::where(['tabla' => 'categorias', 'registro_id' => $categoriaId, 'campo' => '*'])->first();

    expect($filaProveedor)->not->toBeNull()
        ->and($filaProveedor->usuario_id)->toBe($logistica->id)
        ->and($filaProveedor->valor_anterior)->toBeNull()
        ->and(json_decode($filaProveedor->valor_nuevo, true))->toMatchArray(['nombre' => 'Auditado SAS', 'nit' => '800-1'])
        ->and($filaCategoria)->not->toBeNull()
        ->and(json_decode($filaCategoria->valor_nuevo, true))->toMatchArray(['nombre' => 'Auditada']);
});
