<?php

use App\Enums\Rol;
use App\Models\Auditoria;
use App\Models\Proveedor;

/*
 * S-29 (amplía S-24; RF-11, RF-16): NIT obligatorio al crear un proveedor, asesor opcional.
 * Un NIT guardado no se borra; los proveedores antiguos sin NIT siguen usables.
 */

beforeEach(fn () => sembrarDemo());

it('S-29 (a): crear sin NIT responde 422; con NIT y sin asesor, 201', function () {
    actuarComo(Rol::Logistica);

    $this->postJson('/api/proveedores', ['nombre' => 'Sin NIT SAS'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['nit' => 'Escriba el NIT del proveedor.']);
    $this->postJson('/api/proveedores', ['nombre' => 'Sin NIT SAS', 'nit' => '   '])->assertUnprocessable()->assertJsonValidationErrors('nit');
    expect(Proveedor::where('nombre', 'Sin NIT SAS')->exists())->toBeFalse();

    $this->postJson('/api/proveedores', ['nombre' => 'Con NIT SAS', 'nit' => '900123456-7'])
        ->assertCreated()
        ->assertJsonPath('nit', '900123456-7')
        ->assertJsonPath('asesor', null);
});

it('S-29 (b): el asesor se guarda recortado; vacío se guarda como null', function () {
    actuarComo(Rol::Logistica);

    $id = $this->postJson('/api/proveedores', ['nombre' => 'Asesorado', 'nit' => '800.1', 'asesor' => '  Juan Pérez '])
        ->assertCreated()->assertJsonPath('asesor', 'Juan Pérez')->json('id');
    expect(Proveedor::find($id)->asesor)->toBe('Juan Pérez');

    $id = $this->postJson('/api/proveedores', ['nombre' => 'Sin asesor', 'nit' => '800.2', 'asesor' => '   '])
        ->assertCreated()->assertJsonPath('asesor', null)->json('id');
    expect(Proveedor::find($id)->asesor)->toBeNull();

    $this->postJson('/api/proveedores', ['nombre' => 'Asesor largo', 'nit' => '800.3', 'asesor' => str_repeat('a', 121)])
        ->assertUnprocessable()->assertJsonValidationErrors('asesor');

    $fila = collect($this->getJson('/api/proveedores')->assertOk()->json())->firstWhere('nombre', 'Asesorado');
    expect(array_keys($fila))->toBe(['id', 'nombre', 'nit', 'asesor', 'plazo_dias']);
});

it('S-29 (c): un NIT repetido con otros puntos o guiones responde 422 con el proveedor existente', function (string $nit) {
    actuarComo(Rol::Logistica);
    $existente = Proveedor::create(['nombre' => 'Repuestos Ochoa', 'nit' => '900.123.456-7']);

    $this->postJson('/api/proveedores', ['nombre' => 'Otro', 'nit' => $nit])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('nit')
        ->assertJsonPath('proveedor_existente_id', $existente->id)
        ->assertJsonPath('proveedor_existente', 'Repuestos Ochoa');
})->with(['9001234567', '900-123-456-7', ' 900 123 456 7 ']);

it('S-29 (c): un nombre repetido sin NIT sigue ofreciendo el proveedor existente', function () {
    actuarComo(Rol::Logistica);
    $existente = Proveedor::where('nombre', 'Autopartes Central')->firstOrFail();

    $this->postJson('/api/proveedores', ['nombre' => 'autopartes central'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['nombre', 'nit'])
        ->assertJsonPath('proveedor_existente_id', $existente->id);
});

it('S-29 (d): un NIT con letras o símbolos raros responde 422', function (string $nit) {
    actuarComo(Rol::Logistica);

    $this->postJson('/api/proveedores', ['nombre' => 'NIT raro', 'nit' => $nit])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['nit' => 'El NIT solo puede tener números, puntos, guion y espacios (ej.: 900123456-7).']);
})->with(['ABC123', '900/123', '900_123', '#9001', '---', '9001234567x']);

it('S-29: un NIT de más de 30 caracteres responde 422', function () {
    actuarComo(Rol::Logistica);
    $this->postJson('/api/proveedores', ['nombre' => 'NIT largo', 'nit' => str_repeat('9', 31)])
        ->assertUnprocessable()->assertJsonValidationErrors('nit');
});

it('S-29 (e) / RF-11: editar el asesor y el NIT deja auditoría con anterior, nuevo, usuario y fecha', function () {
    $admin = actuarComo(Rol::Admin);
    $proveedor = Proveedor::create(['nombre' => 'Auditable SAS', 'nit' => '900.111.222-3', 'asesor' => 'Ana']);

    $this->putJson("/api/proveedores/{$proveedor->id}", ['nit' => '900111222-3', 'asesor' => 'Beatriz Gómez'])
        ->assertOk()->assertJsonPath('nit', '900111222-3')->assertJsonPath('asesor', 'Beatriz Gómez');

    foreach ([['nit', '900.111.222-3', '900111222-3'], ['asesor', 'Ana', 'Beatriz Gómez']] as [$campo, $antes, $despues]) {
        $this->assertDatabaseHas('auditoria', [
            'tabla' => 'proveedores', 'registro_id' => $proveedor->id, 'campo' => $campo,
            'valor_anterior' => $antes, 'valor_nuevo' => $despues, 'usuario_id' => $admin->id,
        ]);
    }
    expect(Auditoria::where('tabla', 'proveedores')->where('registro_id', $proveedor->id)->whereNull('created_at')->exists())->toBeFalse();

    // Quitar el asesor también queda auditado.
    $this->patchJson("/api/proveedores/{$proveedor->id}", ['asesor' => ''])->assertOk()->assertJsonPath('asesor', null);
    $this->assertDatabaseHas('auditoria', ['tabla' => 'proveedores', 'registro_id' => $proveedor->id, 'campo' => 'asesor', 'valor_anterior' => 'Beatriz Gómez', 'valor_nuevo' => null]);
});

it('S-29 (f): al editar no se puede dejar vacío un NIT ya guardado; uno antiguo sin NIT se edita sin enviarlo', function () {
    actuarComo(Rol::Admin);
    $conNit = Proveedor::create(['nombre' => 'Con NIT', 'nit' => '900.555']);

    $this->patchJson("/api/proveedores/{$conNit->id}", ['nit' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['nit' => 'El NIT no se puede dejar vacío: cámbielo si está mal.']);
    $this->patchJson("/api/proveedores/{$conNit->id}", ['nit' => null])->assertUnprocessable();
    expect($conNit->fresh()->nit)->toBe('900.555');

    // Proveedor antiguo (sembrado sin NIT): se edita sin NIT y luego se le asigna uno.
    $antiguo = Proveedor::where('nombre', 'Autopartes Central')->firstOrFail();
    expect($antiguo->nit)->toBeNull();
    $this->patchJson("/api/proveedores/{$antiguo->id}", ['asesor' => 'Carlos'])->assertOk()->assertJsonPath('nit', null);
    $this->patchJson("/api/proveedores/{$antiguo->id}", ['nit' => '901.000.000-1'])->assertOk()->assertJsonPath('nit', '901.000.000-1');
    $this->patchJson("/api/proveedores/{$antiguo->id}", ['nit' => ''])->assertUnprocessable();
});

it('S-29 / criterio 6: un proveedor antiguo sin NIT se sigue usando en ingresos', function () {
    actuarComo(Rol::Logistica);
    $antiguo = Proveedor::where('nombre', 'Autopartes Central')->firstOrFail();

    $this->postJson('/api/ingresos', ['tipo' => 'remision', 'proveedor_id' => $antiguo->id, 'numero_documento' => 'REM-S29', 'fecha' => now()->toDateString()])
        ->assertCreated();
});

it('S-29 (g): logística crea pero no edita; admin crea y edita; mostrador 403 en ambos', function () {
    $proveedor = Proveedor::where('nombre', 'Distribuidora Eléctrica')->firstOrFail();

    actuarComo(Rol::Logistica);
    $this->postJson('/api/proveedores', ['nombre' => 'De logística', 'nit' => '700.1'])->assertCreated();
    $this->patchJson("/api/proveedores/{$proveedor->id}", ['asesor' => 'X'])->assertForbidden();

    actuarComo(Rol::Admin);
    $this->postJson('/api/proveedores', ['nombre' => 'Del admin', 'nit' => '700.2'])->assertCreated();
    $this->patchJson("/api/proveedores/{$proveedor->id}", ['asesor' => 'Y', 'nit' => '700.3'])->assertOk();

    actuarComo(Rol::Mostrador);
    $this->postJson('/api/proveedores', ['nombre' => 'De mostrador', 'nit' => '700.4'])->assertForbidden();
    $this->patchJson("/api/proveedores/{$proveedor->id}", ['asesor' => 'Z'])->assertForbidden();

    expect($proveedor->fresh()->asesor)->toBe('Y');
});
