<?php

use App\Enums\Rol;
use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Proveedor;
use App\Models\Referencia;

/*
 * S-26 (RF-02, RF-11, regla 14): logística y admin crean, editan, desactivan y reactivan
 * referencias; mostrador no gestiona catálogo. No hay DELETE. Todo queda auditado.
 */

beforeEach(fn () => sembrarDemo());

function datosNuevaReferencia(string $codigo): array
{
    return [
        'codigo_referencia' => $codigo,
        'proveedor_id' => Proveedor::where('nombre', 'Repuestos del Norte')->value('id'),
        'categoria_id' => Categoria::where('admite_codigo_universal', false)->value('id'),
        'descripcion' => 'Pieza S-26',
        'precio_venta' => 12000,
    ];
}

it('S-26 (a): logística y admin crean y editan una referencia; mostrador recibe 403', function (Rol $rol) {
    actuarComo($rol);

    $id = $this->postJson('/api/referencias', datosNuevaReferencia('S26-'.$rol->value))->assertCreated()->json('id');
    $this->putJson("/api/referencias/{$id}", ['precio_venta' => 15000, 'descripcion' => 'Pieza S-26 editada'])
        ->assertOk()->assertJsonPath('precio_venta', '15000.00')->assertJsonPath('descripcion', 'Pieza S-26 editada');
})->with([Rol::Logistica, Rol::Admin]);

it('S-26 (a): mostrador no crea ni edita referencias', function () {
    actuarComo(Rol::Mostrador);

    $this->postJson('/api/referencias', datosNuevaReferencia('S26-M'))->assertForbidden();
    $this->patchJson('/api/referencias/'.referencia('RN-4410')->id, ['precio_venta' => 1])->assertForbidden();
    $this->postJson('/api/referencias/'.referencia('SEN-TEMP-01')->id.'/desactivar')->assertForbidden();
    $this->postJson('/api/referencias/'.referencia('SEN-TEMP-01')->id.'/reactivar')->assertForbidden();
});

it('S-26 (b): logística desactiva una referencia sin existencia y la reactiva; con existencia, 422', function () {
    actuarComo(Rol::Logistica);
    $agotada = referencia('SEN-TEMP-01');
    $conExistencia = referencia('RN-4410');

    $this->postJson("/api/referencias/{$agotada->id}/desactivar")->assertOk()->assertJsonPath('activo', false);
    $this->postJson("/api/referencias/{$agotada->id}/reactivar")->assertOk()->assertJsonPath('activo', true);

    $this->postJson("/api/referencias/{$conExistencia->id}/desactivar")
        ->assertUnprocessable()
        ->assertJsonPath('message', "Tiene {$conExistencia->existencia} unidades disponibles; no se puede desactivar.");
    expect($conExistencia->fresh()->activo)->toBeTrue()
        ->and($conExistencia->fresh()->existencia)->toBe($conExistencia->existencia);
});

it('S-26 (c): una inactiva no sale en /buscar, en el listado ni en agotados; con inactivas=1 sí sale', function () {
    actuarComo(Rol::Logistica);
    $agotada = referencia('SEN-TEMP-01');
    $this->postJson("/api/referencias/{$agotada->id}/desactivar")->assertOk();

    expect(collect($this->getJson('/api/buscar?q=SEN-TEMP')->json('referencias'))->pluck('id'))->not->toContain($agotada->id)
        ->and(collect($this->getJson('/api/referencias?q=SEN-TEMP')->json('data'))->pluck('id'))->not->toContain($agotada->id)
        ->and(collect($this->getJson("/api/reportes/agotados?proveedor_id={$agotada->proveedor_id}")->json('data'))->pluck('id'))->not->toContain($agotada->id);

    $this->getJson('/api/referencias?q=SEN-TEMP&inactivas=1')
        ->assertOk()->assertJsonPath('data.0.id', $agotada->id)->assertJsonPath('data.0.activo', false);
});

it('S-26 (d): escanear en un ingreso el código de una inactiva responde 422 y no crea otra', function () {
    actuarComo(Rol::Logistica);
    $agotada = referencia('SEN-TEMP-01');
    $this->postJson("/api/referencias/{$agotada->id}/desactivar")->assertOk();
    $ingreso = $this->postJson('/api/ingresos', [
        'tipo' => 'remision', 'proveedor_id' => $agotada->proveedor_id, 'numero_documento' => 'S26-1', 'fecha' => now()->toDateString(),
    ])->json('id');
    $referencias = Referencia::count();

    $this->postJson("/api/ingresos/{$ingreso}/escanear", ['codigo' => 'SEN-TEMP-01'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'La referencia está inactiva; reactívela para usarla.')
        ->assertJsonPath('motivo', 'referencia_inactiva');
    $this->postJson("/api/ingresos/{$ingreso}/items", ['referencia_id' => $agotada->id, 'cantidad' => 1, 'costo_unitario' => 1000])
        ->assertUnprocessable();

    expect(Referencia::count())->toBe($referencias)
        ->and($this->getJson("/api/ingresos/{$ingreso}")->json('items'))->toBe([]);
});

it('S-26 (e): crear, desactivar, reactivar y editar el precio quedan en la auditoría', function () {
    $logistica = actuarComo(Rol::Logistica);
    $id = $this->postJson('/api/referencias', datosNuevaReferencia('S26-AUD'))->assertCreated()->json('id');

    $this->postJson("/api/referencias/{$id}/desactivar")->assertOk();
    $this->postJson("/api/referencias/{$id}/reactivar")->assertOk();
    $this->patchJson("/api/referencias/{$id}", ['precio_venta' => 13000])->assertOk();

    $filas = Auditoria::where(['tabla' => 'referencias', 'registro_id' => $id])->orderBy('id')->get();
    expect($filas->map(fn ($f) => [$f->campo, $f->valor_anterior, $f->valor_nuevo])->all())->toBe([
        ['*', null, $filas[0]->valor_nuevo],
        ['activo', '1', '0'],
        ['activo', '0', '1'],
        ['precio_venta', '12000.00', '13000.00'],
    ])
        ->and($filas->pluck('usuario_id')->unique()->all())->toBe([$logistica->id]);
});

it('S-26 (f): nadie puede borrar una referencia por la API', function (Rol $rol) {
    actuarComo($rol);
    $referencia = referencia('SEN-TEMP-01');

    $this->deleteJson("/api/referencias/{$referencia->id}")->assertMethodNotAllowed();

    expect(Referencia::whereKey($referencia->id)->exists())->toBeTrue();
})->with([Rol::Admin, Rol::Logistica, Rol::Mostrador]);
