<?php

use App\Enums\Rol;
use App\Models\Ingreso;
use App\Models\IngresoItem;
use App\Models\Referencia;

/*
 * S-86 (RF-09, S-21, S-22, regla 11): en un BORRADOR, el descuento por línea y el del documento (en $ o %)
 * se excluyen. No aplica a confirmados ni a la corrección de RF-28; un borrador viejo que ya mezcla ambos
 * calcula igual que siempre (TotalesIngreso).
 */

const MSG_S86_LINEA = 'El documento ya tiene descuento: quítelo para dar descuento por línea.';
const MSG_S86_DOC = 'Hay líneas con descuento: quítelos para usar un descuento del documento.';

beforeEach(function () {
    sembrarDemo();
    actuarComo(Rol::Logistica);
});

function borradorS86(array $encabezado = []): int
{
    return test()->postJson('/api/ingresos', [
        'tipo' => 'factura', 'proveedor_id' => referencia('RN-4410')->proveedor_id,
        'numero_documento' => 'S86-'.uniqid(), 'fecha' => '2026-11-21', ...$encabezado,
    ])->assertCreated()->json('id');
}

function lineaS86(int $ingresoId, array $cambios = [], ?Referencia $ref = null)
{
    $ref ??= Referencia::factory()->create(['proveedor_id' => Ingreso::findOrFail($ingresoId)->proveedor_id, 'precio_venta' => 50000]);

    return test()->postJson("/api/ingresos/{$ingresoId}/items", [
        'referencia_id' => $ref->id, 'cantidad' => 1, 'costo_unitario' => 100000, 'iva_porcentaje' => 19, ...$cambios,
    ]);
}

it('S-86: una línea con descuento y llega descuento del documento (en $ o en %) responde 422 en descuento_documento', function () {
    $id = borradorS86();
    lineaS86($id, ['descuento_porcentaje' => 10])->assertOk();

    $this->patchJson("/api/ingresos/{$id}", ['descuento_documento' => 5000])
        ->assertUnprocessable()->assertJsonValidationErrors('descuento_documento')
        ->assertJsonPath('errors.descuento_documento.0', MSG_S86_DOC);
    $this->patchJson("/api/ingresos/{$id}", ['descuento_documento_porcentaje' => 5])
        ->assertUnprocessable()->assertJsonPath('errors.descuento_documento.0', MSG_S86_DOC);

    expect(Ingreso::find($id)->descuento_documento)->toBe('0.00');
});

it('S-86: un documento con descuento rechaza el descuento de línea al agregar y actualizar (escanear crea la línea con 0)', function () {
    $id = borradorS86(['descuento_documento' => 10000]);
    $conPorcentaje = borradorS86(['descuento_documento_porcentaje' => 10]);
    $ref = referencia('RN-4410');
    $linea = lineaS86($id, ['descuento_porcentaje' => 0])->assertOk()->json('items.0.id');

    // agregarItem (en $ y en %)
    lineaS86($id, ['descuento_porcentaje' => 5])
        ->assertUnprocessable()->assertJsonValidationErrors('descuento_porcentaje')
        ->assertJsonPath('errors.descuento_porcentaje.0', MSG_S86_LINEA);
    lineaS86($conPorcentaje, ['descuento_porcentaje' => 5])
        ->assertUnprocessable()->assertJsonPath('errors.descuento_porcentaje.0', MSG_S86_LINEA);

    // actualizarItem
    $this->patchJson("/api/ingresos/{$id}/items/{$linea}", ['descuento_porcentaje' => 5])
        ->assertUnprocessable()->assertJsonPath('errors.descuento_porcentaje.0', MSG_S86_LINEA);

    // escanear crea la línea con descuento 0, así que no choca con el descuento del documento.
    $escaneo = $this->postJson("/api/ingresos/{$id}/escanear", ['codigo' => $ref->codigo_referencia])->assertCreated();
    expect(lineaEscaneada($escaneo)['descuento_porcentaje'])->toBe('0.00');

    expect(IngresoItem::where('ingreso_id', $id)->count())->toBe(2)
        ->and(IngresoItem::where('ingreso_id', $id)->max('descuento_porcentaje'))->toBe('0.00');
});

it('S-86: cero en ambos no bloquea', function () {
    $id = borradorS86(['descuento_documento' => 10000]);

    lineaS86($id, ['descuento_porcentaje' => 0])->assertOk();
    $this->patchJson("/api/ingresos/{$id}", ['descuento_documento' => 0])->assertOk();
    lineaS86($id, ['descuento_porcentaje' => 10])->assertOk(); // documento en 0: ya se puede dar descuento de línea
    $this->patchJson("/api/ingresos/{$id}", ['descuento_documento' => 0])->assertOk(); // 0 con líneas con descuento no bloquea
    $this->patchJson("/api/ingresos/{$id}", ['descuento_documento_porcentaje' => 0])->assertOk();
});

it('S-86: quitar el descuento de línea libera el del documento', function () {
    $id = borradorS86();
    $linea = lineaS86($id, ['descuento_porcentaje' => 10])->assertOk()->json('items.0.id');
    $this->patchJson("/api/ingresos/{$id}", ['descuento_documento' => 5000])->assertUnprocessable();

    $this->patchJson("/api/ingresos/{$id}/items/{$linea}", ['descuento_porcentaje' => 0])->assertOk();

    $this->patchJson("/api/ingresos/{$id}", ['descuento_documento' => 5000])
        ->assertOk()->assertJsonPath('descuento_documento', '5000.00');
    // y ahora es el documento el que bloquea el descuento de línea
    $this->patchJson("/api/ingresos/{$id}/items/{$linea}", ['descuento_porcentaje' => 10])->assertUnprocessable();
});

it('S-86: un borrador viejo que ya mezcla ambos descuentos deja editar cantidad y encabezado sin cambiar el descuento', function () {
    $ref = referencia('RN-4410');
    $id = borradorS86();
    $item = $this->postJson("/api/ingresos/{$id}/items", ['referencia_id' => $ref->id, 'cantidad' => 1, 'costo_unitario' => 1000, 'descuento_porcentaje' => 10])
        ->assertOk()->json('items.0.id');
    // Estado heredado: el documento ya tiene descuento además de la línea.
    Ingreso::whereKey($id)->update(['descuento_documento' => 50]);

    $this->putJson("/api/ingresos/{$id}/items/{$item}", ['cantidad' => 3, 'descuento_porcentaje' => 10])->assertOk();
    $this->putJson("/api/ingresos/{$id}", ['descuento_documento' => 50])->assertOk();
    // Un descuento de línea distinto sí se frena.
    $this->putJson("/api/ingresos/{$id}/items/{$item}", ['descuento_porcentaje' => 15])->assertUnprocessable();
});
