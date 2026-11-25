<?php

use App\Enums\Rol;
use App\Models\AjusteInventario;
use App\Models\Cliente;
use App\Models\Referencia;
use App\Models\Reverso;
use App\Models\Unidad;
use Illuminate\Support\Facades\DB;

/*
 * RF-21 (S-38; ajusta S-34): salidas con cantidad por código de proveedor o fabricante. Las ayudas
 * (refS38, codigosS38, venderS38) están en tests/Pest.php.
 */

beforeEach(fn () => sembrarDemo());

it('S-38 (f): salida con cantidad 5 por código de proveedor: 5 unidades distintas (S-34); excluir[] no repite; con 3 y cantidad 5, 422', function () {
    $ref = refS38('CANT-1', 7);
    $ids = Unidad::where('referencia_id', $ref->id)->orderBy('id')->pluck('id')->all();
    Unidad::whereKey($ids[0])->update(['etiqueta_impresa_at' => now()]);

    actuarComo(Rol::Logistica);
    $r = $this->getJson('/api/ajustes/unidad?tipo=baja&codigo=CANT-1&cantidad=5')->assertOk()->assertJsonCount(5, 'unidades');
    // Primero las sin etiqueta impresa (de la más antigua), la etiquetada al final.
    expect(collect($r->json('unidades'))->pluck('id')->all())->toBe(array_slice($ids, 1, 5))
        ->and(collect($r->json('unidades'))->pluck('origen_codigo')->unique()->all())->toBe(['referencia']);

    $excluir = implode('&', array_map(fn ($id) => "excluir[]={$id}", array_slice($ids, 1, 5)));
    $resto = $this->getJson("/api/ajustes/unidad?tipo=baja&codigo=CANT-1&cantidad=2&{$excluir}")->assertOk();
    expect(collect($resto->json('unidades'))->pluck('id')->all())->toBe([$ids[6], $ids[0]]);

    refS38('CANT-3', 3);
    $this->getJson('/api/ajustes/unidad?tipo=baja&codigo=CANT-3&cantidad=5')->assertUnprocessable()
        ->assertJsonPath('disponibles', 3)
        ->assertJsonPath('message', fn ($m) => str_starts_with($m, 'Solo hay 3 unidades disponibles de CANT-3'));
});

it('S-38 (g): una etiqueta del local con cantidad mayor que 1 responde 422', function () {
    $ref = refS38('ETQ-G', 2);
    [$a] = codigosS38($ref);

    actuarComo(Rol::Logistica);
    $this->getJson("/api/ajustes/unidad?tipo=baja&codigo={$a}&cantidad=2")->assertUnprocessable()
        ->assertJsonPath('message', "Cada etiqueta es una unidad: {$a} se agrega de a una (cantidad 1).");
    $this->getJson("/api/ajustes/unidad?tipo=baja&codigo={$a}&cantidad=1")->assertOk()->assertJsonCount(1, 'unidades');
});

it('S-38 (h): un lote de 10 unidades es atómico: si una se vendió en medio, no se retira ninguna y se dice cuál', function () {
    $ref = refS38('LOTE-10', 10);

    actuarComo(Rol::Logistica);
    $unidades = collect($this->getJson('/api/ajustes/unidad?tipo=baja&codigo=LOTE-10&cantidad=10')->assertOk()->json('unidades'));
    $vendida = $unidades[4]['codigo_barras'];
    venderS38([$vendida]);

    $this->postJson('/api/ajustes/salidas', [
        'motivo' => 'dano',
        'codigos' => $unidades->map(fn ($u) => ['codigo' => 'LOTE-10', 'metodo_captura' => 'escaneado', 'origen_codigo' => 'referencia', 'unidad_codigo' => $u['codigo_barras']])->all(),
    ])->assertUnprocessable()
        ->assertJsonPath('codigo', $vendida)
        ->assertJsonPath('message', "La unidad {$vendida} está vendida; no se puede retirar.");

    expect(AjusteInventario::count())->toBe(0)
        ->and(Unidad::where('referencia_id', $ref->id)->where('estado', 'retirada')->count())->toBe(0)
        ->and($ref->refresh()->existencia)->toBe(9);
});
