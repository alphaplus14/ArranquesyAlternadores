<?php

use App\Enums\Rol;
use App\Models\Unidad;

/*
 * RF-03 (S-39): imprimir las etiquetas de las unidades de un ajuste de alta, con el mismo selector de
 * tamaño; imprimirlas marca etiqueta_impresa_at (S-31).
 */

beforeEach(fn () => sembrarDemo());

it('S-39: imprime las etiquetas de una entrada en los tres tamaños y las marca como impresas', function () {
    $ref = refS38('ETQ-ALTA', 0);
    actuarComo(Rol::Logistica);
    $id = test()->postJson('/api/ajustes/entradas', ['referencia_id' => $ref->id, 'cantidad' => 3, 'motivo' => 'sobrante_inventario', 'metodo_captura' => 'escaneado'])->json('id');

    foreach (['grande', 'pequeno', 'mas_pequeno'] as $tamano) {
        $this->post('/api/etiquetas', ['ajuste_id' => $id, 'tamano' => $tamano])->assertOk()->assertHeader('content-type', 'application/pdf');
    }
    expect(Unidad::where('ajuste_id', $id)->whereNotNull('etiqueta_impresa_at')->count())->toBe(3);
});

it('S-39: las etiquetas por ajuste solo aceptan una entrada', function () {
    $ref = refS38('ETQ-AJ', 1);
    [$a] = codigosS38($ref);
    actuarComo(Rol::Logistica);
    $baja = $this->postJson('/api/ajustes/salidas', ['motivo' => 'dano', 'codigos' => [['codigo' => $a, 'metodo_captura' => 'escaneado']]])->json('id');

    $this->postJson('/api/etiquetas', ['ajuste_id' => $baja, 'tamano' => 'pequeno'])->assertUnprocessable();
    $this->postJson('/api/etiquetas', ['ajuste_id' => 999999, 'tamano' => 'pequeno'])->assertNotFound();
});
