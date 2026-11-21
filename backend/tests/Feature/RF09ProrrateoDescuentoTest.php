<?php

use App\Enums\Rol;
use App\Models\Categoria;
use App\Models\IngresoItem;
use App\Models\Marca;
use App\Models\Proveedor;
use App\Models\Referencia;
use App\Support\TotalesIngreso;

/** Líneas en memoria para el cálculo (sin base de datos). */
function lineas(array $definiciones): array
{
    return array_map(function (array $d) {
        $item = new IngresoItem([
            'cantidad' => $d[0],
            'costo_unitario' => $d[1],
            'descuento' => $d[2] ?? 0,
            'iva_porcentaje' => $d[3] ?? 19,
            'sin_iva' => $d[4] ?? false,
        ]);
        $item->id = $d[5];

        return $item;
    }, $definiciones);
}

/** Factura real FEV 1545: 5 líneas al 19 %, subtotal 735.000. */
function lineasFev1545(): array
{
    return lineas([
        [2, 100000, 0, 19, false, 1],
        [1, 150000, 0, 19, false, 2],
        [3, 45000, 0, 19, false, 3],
        [4, 32500, 0, 19, false, 4],
        [1, 120000, 0, 19, false, 5],
    ]);
}

function cerca(string $valor, int $esperado, int $tolerancia = 6): bool
{
    return abs((float) $valor - $esperado) <= $tolerancia;
}

it('RF-09: la factura FEV 1545 da base 624.750, IVA 118.703 y total 743.453, no 764.400', function () {
    $t = TotalesIngreso::calcular(lineasFev1545(), '110250.00');

    expect($t->subtotal)->toBe('735000.00')
        ->and($t->descuentoDocumento)->toBe('110250.00')
        ->and($t->base)->toBe('624750.00')
        ->and(cerca($t->iva, 118703))->toBeTrue()
        ->and(cerca($t->total, 743453))->toBeTrue()
        ->and($t->total)->not->toBe('764400.00');
});

it('RF-09: sin descuento del documento, el IVA va sobre el subtotal completo', function () {
    $t = TotalesIngreso::calcular(lineasFev1545(), 0);

    expect($t->base)->toBe('735000.00')
        ->and($t->iva)->toBe('139650.00')
        ->and($t->total)->toBe('874650.00');
});

it('RF-09 / RF-10: con líneas sin IVA y descuento, las líneas sin IVA no suman impuesto', function () {
    // 100.000 al 19 % y 100.000 sin IVA; descuento 20.000 (10 %) prorrateado: base 90.000 cada una.
    $t = TotalesIngreso::calcular(lineas([
        [1, 100000, 0, 19, false, 1],
        [1, 100000, 0, 19, true, 2],
    ]), 20000);

    expect($t->base)->toBe('180000.00')
        ->and($t->iva)->toBe('17100.00')
        ->and($t->total)->toBe('197100.00')
        ->and($t->lineas[1])->toBe(['subtotal' => '100000.00', 'base' => '90000.00', 'iva' => '17100.00'])
        ->and($t->lineas[2]['iva'])->toBe('0.00')
        ->and($t->lineasSinIva)->toBe(1);
});

it('RF-09: también con IVA 0 % en una línea que no está marcada sin IVA', function () {
    $t = TotalesIngreso::calcular(lineas([
        [1, 50000, 0, 0, false, 1],
        [1, 50000, 0, 19, false, 2],
    ]), 10000);

    expect($t->iva)->toBe('8550.00')->and($t->total)->toBe('98550.00');
});

it('RF-09: el descuento de línea se resta antes del prorrateo', function () {
    // 10 × 10.000 − 5.000 = 95.000; descuento del documento 15.000 → base 80.000; IVA 15.200.
    $t = TotalesIngreso::calcular(lineas([[10, 10000, 5000, 19, false, 1]]), 15000);

    expect($t->subtotal)->toBe('95000.00')
        ->and($t->descuentoLineas)->toBe('5000.00')
        ->and($t->base)->toBe('80000.00')
        ->and($t->iva)->toBe('15200.00')
        ->and($t->total)->toBe('95200.00');
});

it('RF-09: un descuento igual al subtotal deja base, IVA y total en 0', function () {
    $t = TotalesIngreso::calcular(lineasFev1545(), 735000);

    expect($t->base)->toBe('0.00')
        ->and($t->iva)->toBe('0.00')
        ->and($t->total)->toBe('0.00')
        ->and($t->descuentoExcedeSubtotal())->toBeFalse();
});

it('RF-09: con subtotal 0 (sin líneas) todo queda en 0', function () {
    $t = TotalesIngreso::calcular([], 0);

    expect($t->subtotal)->toBe('0.00')
        ->and($t->iva)->toBe('0.00')
        ->and($t->total)->toBe('0.00')
        ->and($t->lineas)->toBe([]);
});

it('RF-09: el IVA y el total se redondean al peso solo al final (mitad hacia arriba)', function () {
    expect(TotalesIngreso::redondearAlPeso('118702.5'))->toBe('118703.00')
        ->and(TotalesIngreso::redondearAlPeso('118702.4999'))->toBe('118702.00')
        ->and(TotalesIngreso::redondearAlPeso('-3.5'))->toBe('-4.00');
});

describe('por la API', function () {
    beforeEach(fn () => sembrarDemo());

    /** Factura FEV 1545 con 5 referencias nuevas del primer proveedor; devuelve el id. */
    function facturaFev1545(string $descuento = '110250'): int
    {
        $proveedor = Proveedor::orderBy('id')->firstOrFail();
        $id = test()->postJson('/api/ingresos', [
            'tipo' => 'factura', 'proveedor_id' => $proveedor->id, 'numero_documento' => 'FEV 1545',
            'fecha' => '2026-11-21', 'descuento_total' => $descuento,
        ])->assertCreated()->json('id');

        foreach ([[2, 100000], [1, 150000], [3, 45000], [4, 32500], [1, 120000]] as $n => [$cantidad, $costo]) {
            $referencia = Referencia::create([
                'codigo_referencia' => "AP-{$n}", 'proveedor_id' => $proveedor->id, 'marca_id' => Marca::value('id'),
                'categoria_id' => Categoria::where('admite_codigo_universal', false)->value('id'),
                'descripcion' => "Línea {$n}", 'precio_venta' => 1000,
            ]);
            test()->postJson("/api/ingresos/{$id}/items", [
                'referencia_id' => $referencia->id, 'cantidad' => $cantidad, 'costo_unitario' => $costo, 'iva_porcentaje' => 19,
            ])->assertOk();
        }

        return $id;
    }

    it('RF-09: el ingreso FEV 1545 muestra el total 743.453 antes y después de confirmar', function () {
        actuarComo(Rol::Logistica);
        $id = facturaFev1545();

        $totales = $this->getJson("/api/ingresos/{$id}")->assertOk()->json('totales');
        expect($totales['base'])->toBe('624750.00')
            ->and(cerca($totales['iva'], 118703))->toBeTrue()
            ->and(cerca($totales['total'], 743453))->toBeTrue();

        $total = $this->postJson("/api/ingresos/{$id}/confirmar")->assertOk()->json('totales.total');
        expect(cerca($total, 743453))->toBeTrue()->and($total)->not->toBe('764400.00');
    });

    it('RF-09: un descuento del documento mayor que el subtotal responde 422 al editar y al confirmar', function () {
        actuarComo(Rol::Logistica);
        $id = facturaFev1545('0');

        $this->patchJson("/api/ingresos/{$id}", ['descuento_total' => 735000.01])
            ->assertStatus(422)
            ->assertJsonValidationErrors('descuento_total');

        // Quitar líneas puede dejar el descuento por encima: confirmar lo vuelve a revisar.
        $this->patchJson("/api/ingresos/{$id}", ['descuento_total' => 700000])->assertOk();
        $primera = IngresoItem::where('ingreso_id', $id)->orderByDesc('costo_unitario')->value('id');
        $this->deleteJson("/api/ingresos/{$id}/items/{$primera}")->assertOk();

        $this->postJson("/api/ingresos/{$id}/confirmar")->assertStatus(422)->assertJsonValidationErrors('descuento_total');
        expect($this->getJson("/api/ingresos/{$id}")->json('estado'))->toBe('borrador');
    });

    it('RF-09: un descuento negativo no pasa la validación', function () {
        actuarComo(Rol::Logistica);
        $id = facturaFev1545('0');

        $this->patchJson("/api/ingresos/{$id}", ['descuento_total' => -1])->assertJsonValidationErrors('descuento_total');
    });
});
