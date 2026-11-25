<?php

use App\Enums\Rol;
use App\Models\AjusteInventario;
use App\Models\Proveedor;
use App\Models\Reverso;
use App\Models\Unidad;

/*
 * S-44 (RF-21, RF-07, regla 14): en Ajustes logística opera y el admin solo consulta el historial.
 * El admin conserva Ingresos y Etiquetas completos.
 */

beforeEach(fn () => sembrarDemo());

it('S-44: el admin recibe 403 en cada operación de ajustes y devoluciones, y en sus búsquedas auxiliares', function () {
    $ref = refS38('S44-OP', 2);
    [$codigo] = codigosS38($ref);
    [, $vendido] = venderComoMostrador('RN-4410');
    $lote = [['codigo' => $codigo, 'metodo_captura' => 'escaneado']];

    actuarComo(Rol::Admin);
    $this->getJson("/api/ajustes/unidad?tipo=baja&codigo={$codigo}")->assertForbidden()->assertJsonPath('message', 'Su rol no tiene acceso a este módulo.');
    $this->getJson('/api/ajustes/referencia?codigo=S44-OP')->assertForbidden();
    $this->postJson('/api/ajustes/salidas', ['motivo' => 'perdida', 'codigos' => $lote])->assertForbidden();
    $this->postJson('/api/ajustes/reintegros', ['motivo' => 'error_de_registro', 'codigos' => $lote])->assertForbidden();
    $this->postJson('/api/ajustes/entradas', ['referencia_id' => $ref->id, 'cantidad' => 1, 'motivo' => 'sobrante_inventario', 'metodo_captura' => 'escaneado'])->assertForbidden();
    $this->getJson("/api/devoluciones/consulta?codigo={$vendido}")->assertForbidden();
    $this->postJson('/api/devoluciones', ['codigo' => $vendido, 'motivo' => 'Prueba', 'metodo_captura' => 'escaneado'])->assertForbidden();

    expect(AjusteInventario::count())->toBe(0)
        ->and(Reverso::count())->toBe(0)
        ->and($ref->refresh()->existencia)->toBe(2);
});

it('S-44: el admin consulta el historial de ajustes con filtros', function () {
    $ref = refS38('S44-H', 2);
    [$a] = codigosS38($ref);
    actuarComo(Rol::Logistica);
    $this->postJson('/api/ajustes/salidas', ['motivo' => 'perdida', 'codigos' => [['codigo' => $a, 'metodo_captura' => 'escaneado']]])->assertCreated();
    $this->postJson('/api/ajustes/entradas', ['referencia_id' => $ref->id, 'cantidad' => 1, 'motivo' => 'sobrante_inventario', 'metodo_captura' => 'digitado'])->assertCreated();

    actuarComo(Rol::Admin);
    $this->getJson('/api/ajustes')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/ajustes?tipo=baja&motivo=perdida')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/ajustes?tipo=alta')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/ajustes?desde='.now('America/Bogota')->toDateString().'&hasta='.now('America/Bogota')->toDateString())->assertOk()->assertJsonCount(2, 'data');
});

it('S-44: logística sigue operando salidas, reintegros, entradas y devoluciones', function () {
    $ref = refS38('S44-L', 2);
    [$a] = codigosS38($ref);
    [, $vendido] = venderComoMostrador('RN-4410');
    actuarComo(Rol::Logistica);
    $lote = [['codigo' => $a, 'metodo_captura' => 'escaneado']];

    $this->getJson("/api/ajustes/unidad?tipo=baja&codigo={$a}")->assertOk();
    $this->postJson('/api/ajustes/salidas', ['motivo' => 'perdida', 'codigos' => $lote])->assertCreated();
    $this->postJson('/api/ajustes/reintegros', ['motivo' => 'error_de_registro', 'codigos' => $lote])->assertCreated();
    $this->getJson('/api/ajustes/referencia?codigo=S44-L')->assertOk();
    $this->postJson('/api/ajustes/entradas', ['referencia_id' => $ref->id, 'cantidad' => 1, 'motivo' => 'sobrante_inventario', 'metodo_captura' => 'escaneado'])->assertCreated();
    $this->getJson("/api/devoluciones/consulta?codigo={$vendido}")->assertOk();
    $this->postJson('/api/devoluciones', ['codigo' => $vendido, 'motivo' => 'El cliente la devolvió', 'metodo_captura' => 'escaneado'])->assertCreated();

    expect($ref->refresh()->existencia)->toBe(3);
});

it('S-44 (regresión): el admin conserva Ingresos y Etiquetas completos', function () {
    actuarComo(Rol::Admin);
    $proveedor = Proveedor::firstOrFail();

    $this->getJson('/api/ingresos')->assertOk();
    $id = $this->postJson('/api/ingresos', ['tipo' => 'remision', 'proveedor_id' => $proveedor->id, 'numero_documento' => 'S44-REM', 'fecha' => now()->toDateString()])
        ->assertCreated()->json('id');
    $this->getJson("/api/ingresos/{$id}")->assertOk();
    $this->getJson('/api/etiquetas/tamanos')->assertOk();
    $this->postJson('/api/etiquetas', ['unidad_ids' => [Unidad::firstOrFail()->id], 'tamano' => 'grande'])->assertOk();
});
