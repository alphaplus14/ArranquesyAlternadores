<?php

use App\Enums\Rol;
use App\Models\Categoria;
use App\Models\Ingreso;
use App\Models\IngresoItem;
use App\Models\Proveedor;
use App\Models\Referencia;
use App\Support\TotalesIngreso;

/*
 * S-66 (ajuste, RF-09, RF-16, S-22, regla 11): el descuento del documento guardado sin líneas (o mayor
 * que el subtotal) se muestra como "por aplicar" y el total visible del borrador no es negativo. La
 * regla de confirmar no cambia: con descuento mayor que el subtotal neto o sin líneas no se confirma.
 */

beforeEach(fn () => sembrarDemo());

/** Ingreso borrador vacío de factura a contado; devuelve el id. */
function ingresoVacioS66(array $encabezado = []): int
{
    return test()->postJson('/api/ingresos', [
        'tipo' => 'factura', 'proveedor_id' => Proveedor::orderBy('id')->value('id'), 'numero_documento' => 'S66 '.uniqid(),
        'fecha' => '2026-11-21', 'forma_pago' => 'contado', ...$encabezado,
    ])->assertCreated()->json('id');
}

/** Agrega una línea sin IVA (el total se lee directo) y devuelve su id. */
function lineaS66(int $ingresoId, int $costo): int
{
    $ingreso = Ingreso::findOrFail($ingresoId);
    $ref = Referencia::create([
        'codigo_referencia' => 'S66-'.uniqid(), 'proveedor_id' => $ingreso->proveedor_id,
        'categoria_id' => Categoria::where('admite_codigo_universal', false)->value('id'),
        'descripcion' => 'Pieza S-66', 'precio_venta' => 1000, 'iva_venta_porcentaje' => 19,
    ]);
    test()->postJson("/api/ingresos/{$ingresoId}/items", [
        'referencia_id' => $ref->id, 'cantidad' => 1, 'costo_unitario' => $costo, 'descuento_porcentaje' => 0, 'iva_porcentaje' => 0,
    ])->assertOk();

    return IngresoItem::where('ingreso_id', $ingresoId)->orderByDesc('id')->value('id');
}

function totalesS66(int $ingresoId): array
{
    return test()->getJson("/api/ingresos/{$ingresoId}")->assertOk()->json('totales');
}

it('S-66: el cálculo deriva aplicado = min(D, subtotal), pendiente = max(0, D − subtotal) y total visible = max(total, 0) sin cambiar el total', function () {
    $vacio = TotalesIngreso::calcular([], 200000);
    expect($vacio->descuentoAplicado)->toBe('0.00')
        ->and($vacio->descuentoPendiente)->toBe('200000.00')
        ->and($vacio->totalVisible)->toBe('0.00')
        ->and($vacio->total)->toBe('-200000.00') // el total interno no cambia
        ->and($vacio->descuentoDocumento)->toBe('200000.00');

    // Factura real FEV 1545 (RF-09): mismos totales de siempre; todo el descuento queda aplicado.
    $fev = TotalesIngreso::calcular(collect([[2, 100000], [1, 150000], [3, 45000], [4, 32500], [1, 120000]])
        ->map(function (array $l, int $n) {
            $item = new IngresoItem(['cantidad' => $l[0], 'costo_unitario' => $l[1], 'descuento_porcentaje' => 0, 'iva_porcentaje' => 19]);
            $item->id = $n + 1;

            return $item;
        }), 110250);
    expect($fev->total)->toBe('743453.00')
        ->and($fev->descuentoAplicado)->toBe('110250.00')
        ->and($fev->descuentoPendiente)->toBe('0.00')
        ->and($fev->totalVisible)->toBe('743453.00');
});

it('S-66: un descuento en pesos sin líneas se guarda como pendiente, se aplica al agregar líneas y no se confirma mientras exceda el subtotal', function () {
    actuarComo(Rol::Logistica);
    $id = ingresoVacioS66();

    $this->patchJson("/api/ingresos/{$id}", ['descuento_documento' => 200000])->assertOk();
    expect(totalesS66($id))->toMatchArray([
        'descuento_documento' => '200000.00',
        'descuento_aplicado' => '0.00',
        'descuento_pendiente' => '200000.00',
        'total_visible' => '0.00',
        'descuento_excede_subtotal' => true,
    ]);
    $this->postJson("/api/ingresos/{$id}/confirmar")->assertUnprocessable(); // sin líneas

    // Una línea de 150.000: se aplican 150.000 y faltan 50.000; confirmar sigue rechazado.
    $linea = lineaS66($id, 150000);
    expect(totalesS66($id))->toMatchArray([
        'subtotal_neto' => '150000.00',
        'descuento_aplicado' => '150000.00',
        'descuento_pendiente' => '50000.00',
        'total_visible' => '0.00',
        'total' => '-50000.00',
    ]);
    $this->postJson("/api/ingresos/{$id}/confirmar")->assertUnprocessable()->assertJsonValidationErrors('descuento_documento');

    // Con 500.000 el descuento se aplica completo, prorrateado en la base de la línea.
    $this->patchJson("/api/ingresos/{$id}/items/{$linea}", ['costo_unitario' => 500000])->assertOk();
    $respuesta = $this->getJson("/api/ingresos/{$id}")->assertOk();
    expect($respuesta->json('totales'))->toMatchArray([
        'descuento_documento' => '200000.00',
        'descuento_aplicado' => '200000.00',
        'descuento_pendiente' => '0.00',
        'total' => '300000.00',
        'total_visible' => '300000.00',
        'descuento_excede_subtotal' => false,
    ]);

    // Confirmar guarda el total de siempre (TotalesIngreso, sin los derivados).
    $this->postJson("/api/ingresos/{$id}/confirmar")->assertOk();
    $ingreso = Ingreso::findOrFail($id);
    expect($ingreso->estado->value)->toBe('confirmado')
        ->and((string) $ingreso->total)->toBe('300000.00')
        ->and((string) $ingreso->descuento_documento)->toBe('200000.00');
});

it('S-66: un descuento en % sin líneas queda en % (nada pendiente) y se aplica al agregarlas', function () {
    actuarComo(Rol::Logistica);
    $id = ingresoVacioS66();

    $this->patchJson("/api/ingresos/{$id}", ['descuento_documento_porcentaje' => 10])->assertOk()
        ->assertJsonPath('descuento_documento_porcentaje', '10.00');
    expect(totalesS66($id))->toMatchArray(['descuento_documento' => '0.00', 'descuento_aplicado' => '0.00', 'descuento_pendiente' => '0.00', 'total_visible' => '0.00']);

    lineaS66($id, 450000);
    expect(totalesS66($id))->toMatchArray([
        'descuento_documento' => '45000.00',
        'descuento_aplicado' => '45000.00',
        'descuento_pendiente' => '0.00',
        'total' => '405000.00',
        'total_visible' => '405000.00',
    ]);
});
