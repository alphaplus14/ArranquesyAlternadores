<?php

use App\Enums\Rol;
use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Ingreso;
use App\Models\IngresoItem;
use App\Models\Proveedor;
use App\Models\Referencia;
use App\Support\TotalesIngreso;

/**
 * Líneas en memoria para el cálculo (sin base de datos).
 *
 * @param  list<array{0:int, 1:int|string, 2?:int|string, 3?:int|string}>  $definiciones  [cantidad, costo, desc. %, IVA %]
 */
function lineas(array $definiciones): array
{
    return array_map(function (array $d, int $n) {
        $item = new IngresoItem([
            'cantidad' => $d[0],
            'costo_unitario' => $d[1],
            'descuento_porcentaje' => $d[2] ?? 0,
            'iva_porcentaje' => $d[3] ?? 19,
        ]);
        $item->id = $n + 1;

        return $item;
    }, $definiciones, array_keys($definiciones));
}

/** Factura real FEV 1545: 5 líneas al 19 %, subtotal 735.000. */
function lineasFev1545(): array
{
    return lineas([[2, 100000], [1, 150000], [3, 45000], [4, 32500], [1, 120000]]);
}

function cerca(string $valor, int $esperado, int $tolerancia = 6): bool
{
    return abs((float) $valor - $esperado) <= $tolerancia;
}

it('RF-09: la factura FEV 1545 da base 624.750, IVA 118.703 y total 743.453, no 764.400', function () {
    $t = TotalesIngreso::calcular(lineasFev1545(), '110250.00');

    expect($t->subtotalNeto)->toBe('735000.00')
        ->and($t->descuentoDocumento)->toBe('110250.00')
        ->and($t->baseGravada)->toBe('624750.00')
        ->and($t->baseSinIva)->toBe('0.00')
        ->and(cerca($t->iva, 118703))->toBeTrue()
        ->and(cerca($t->total, 743453))->toBeTrue()
        ->and($t->total)->not->toBe('764400.00');
});

it('RF-09: sin ningún descuento, el IVA va sobre el subtotal completo', function () {
    $t = TotalesIngreso::calcular(lineasFev1545(), 0);

    expect($t->baseGravada)->toBe('735000.00')
        ->and($t->iva)->toBe('139650.00')
        ->and($t->total)->toBe('874650.00');
});

it('S-22 (a): solo descuento por línea, una al 10 % y otra al 0 %, sin descuento del documento', function () {
    $t = TotalesIngreso::calcular(lineas([[1, 100000, 10, 19], [1, 50000, 0, 19]]), 0);

    expect($t->subtotalBruto)->toBe('150000.00')
        ->and($t->descuentoLineas)->toBe('10000.00')
        ->and($t->subtotalNeto)->toBe('140000.00')
        ->and($t->descuentoDocumento)->toBe('0.00')
        ->and($t->baseGravada)->toBe('140000.00')
        ->and($t->iva)->toBe('26600.00')
        ->and($t->total)->toBe('166600.00')
        ->and($t->lineas[1])->toBe(['neto' => '90000.00', 'base' => '90000.00', 'iva' => '17100.00']);
});

it('S-22 (b): solo descuento del documento con IVA 19 % y 0 %: la línea sin IVA recibe su parte pero no suma IVA', function () {
    // 100.000 al 19 % y 100.000 al 0 %; descuento 20.000 prorrateado: base 90.000 cada una.
    $t = TotalesIngreso::calcular(lineas([[1, 100000, 0, 19], [1, 100000, 0, 0]]), 20000);

    expect($t->baseGravada)->toBe('90000.00')
        ->and($t->baseSinIva)->toBe('90000.00')
        ->and($t->iva)->toBe('17100.00')
        ->and($t->total)->toBe('197100.00')
        ->and($t->lineas[2])->toBe(['neto' => '100000.00', 'base' => '90000.00', 'iva' => '0.00'])
        ->and($t->lineasSinIva)->toBe(1);
});

it('S-22 (c): con descuento de línea y del documento, el del documento va sobre el neto ya descontado', function () {
    // Netos 90.000 (10 %) y 100.000: subtotal neto 190.000. Descuento 19.000 = 10 % del neto.
    $t = TotalesIngreso::calcular(lineas([[1, 100000, 10, 19], [1, 100000, 0, 19]]), 19000);

    expect($t->subtotalNeto)->toBe('190000.00')
        ->and($t->baseGravada)->toBe('171000.00')
        ->and($t->lineas[1]['base'])->toBe('81000.00')
        ->and($t->lineas[2]['base'])->toBe('90000.00')
        ->and($t->iva)->toBe('32490.00')
        ->and($t->total)->toBe('203490.00');
});

it('S-22 (d): el descuento del documento en % da el mismo total que su equivalente en pesos', function () {
    $lineas = lineas([[1, 100000, 10, 19], [1, 100000, 0, 19]]);

    $enPesos = TotalesIngreso::calcular($lineas, 19000);
    $enPorcentaje = TotalesIngreso::calcular($lineas, 0, 10);

    expect($enPorcentaje->descuentoDocumento)->toBe('19000.00')
        ->and($enPorcentaje->total)->toBe($enPesos->total)
        ->and($enPorcentaje->iva)->toBe($enPesos->iva);

    // FEV 1545 en %: 15 % de 735.000 = 110.250.
    expect(TotalesIngreso::calcular(lineasFev1545(), 0, 15)->total)->toBe(TotalesIngreso::calcular(lineasFev1545(), 110250)->total);
});

it('S-22 (f): IVA de 5 %, 19 % y 0 % en el mismo documento', function () {
    $t = TotalesIngreso::calcular(lineas([[1, 100000, 0, 5], [1, 100000, 0, 19], [1, 100000, 0, 0]]), 0);

    expect($t->baseGravada)->toBe('200000.00')
        ->and($t->baseSinIva)->toBe('100000.00')
        ->and($t->iva)->toBe('24000.00')
        ->and($t->total)->toBe('324000.00');

    // Con descuento del documento de 30.000 (10 %), cada base baja 10 %.
    $d = TotalesIngreso::calcular(lineas([[1, 100000, 0, 5], [1, 100000, 0, 19], [1, 100000, 0, 0]]), 30000);
    expect($d->baseGravada)->toBe('180000.00')
        ->and($d->baseSinIva)->toBe('90000.00')
        ->and($d->iva)->toBe('21600.00')
        ->and($d->total)->toBe('291600.00');
});

it('RF-09: un descuento igual al subtotal neto deja base, IVA y total en 0', function () {
    $t = TotalesIngreso::calcular(lineasFev1545(), 735000);

    expect($t->baseGravada)->toBe('0.00')
        ->and($t->iva)->toBe('0.00')
        ->and($t->total)->toBe('0.00')
        ->and($t->descuentoExcedeSubtotal())->toBeFalse();
});

it('RF-09: con subtotal neto 0 todo queda en 0', function () {
    $t = TotalesIngreso::calcular([], 0);

    expect($t->subtotalNeto)->toBe('0.00')
        ->and($t->iva)->toBe('0.00')
        ->and($t->total)->toBe('0.00')
        ->and($t->lineas)->toBe([]);

    // Líneas con descuento del 100 %: neto 0, base 0.
    $cero = TotalesIngreso::calcular(lineas([[1, 50000, 100, 19]]), 0);
    expect($cero->lineas[1]['base'])->toBe('0.00')->and($cero->total)->toBe('0.00');
});

it('RF-09: el IVA y el total se redondean al peso solo al final (mitad hacia arriba)', function () {
    expect(TotalesIngreso::redondearAlPeso('118702.5'))->toBe('118703.00')
        ->and(TotalesIngreso::redondearAlPeso('118702.4999'))->toBe('118702.00')
        ->and(TotalesIngreso::redondearAlPeso('-3.5'))->toBe('-4.00');
});

describe('por la API', function () {
    beforeEach(fn () => sembrarDemo());

    function referenciaDePrueba(string $codigo, int $proveedorId, int|string $ivaVenta = 19): Referencia
    {
        return Referencia::create([
            'codigo_referencia' => $codigo, 'proveedor_id' => $proveedorId,
            'categoria_id' => Categoria::where('admite_codigo_universal', false)->value('id'),
            'descripcion' => "Pieza {$codigo}", 'precio_venta' => 1000, 'iva_venta_porcentaje' => $ivaVenta,
        ]);
    }

    /**
     * Ingreso con las líneas dadas ([cantidad, costo, desc. %, IVA %]) y el encabezado extra; devuelve el id.
     *
     * @param  list<array{0:int, 1:int, 2?:int|float, 3?:int}>  $lineas
     */
    function ingresoCon(array $lineas, array $encabezado = [], string $tipo = 'factura'): int
    {
        $proveedor = Proveedor::orderBy('id')->firstOrFail();
        $id = test()->postJson('/api/ingresos', [
            'tipo' => $tipo, 'proveedor_id' => $proveedor->id, 'numero_documento' => 'DOC '.uniqid(),
            'fecha' => '2026-11-21', ...$encabezado,
        ])->assertCreated()->json('id');

        foreach ($lineas as $n => $l) {
            test()->postJson("/api/ingresos/{$id}/items", [
                'referencia_id' => referenciaDePrueba("L{$id}-{$n}", $proveedor->id)->id,
                'cantidad' => $l[0], 'costo_unitario' => $l[1],
                'descuento_porcentaje' => $l[2] ?? 0, 'iva_porcentaje' => $l[3] ?? 19,
            ])->assertOk();
        }

        return $id;
    }

    it('RF-09: el ingreso FEV 1545 muestra el total 743.453 antes y después de confirmar', function () {
        actuarComo(Rol::Logistica);
        $id = ingresoCon([[2, 100000], [1, 150000], [3, 45000], [4, 32500], [1, 120000]], ['descuento_documento' => 110250]);

        $totales = $this->getJson("/api/ingresos/{$id}")->assertOk()->json('totales');
        expect($totales['subtotal_bruto'])->toBe('735000.00')
            ->and($totales['base_gravada'])->toBe('624750.00')
            ->and($totales['base_sin_iva'])->toBe('0.00')
            ->and(cerca($totales['iva'], 118703))->toBeTrue()
            ->and(cerca($totales['total'], 743453))->toBeTrue();

        $total = $this->postJson("/api/ingresos/{$id}/confirmar")->assertOk()->json('total');
        expect(cerca($total, 743453))->toBeTrue()->and($total)->not->toBe('764400.00');
    });

    it('S-22 (d): el descuento del documento en % se guarda como % en el borrador y queda en pesos al confirmar', function () {
        actuarComo(Rol::Logistica);
        // Se digita el % al crear, todavía sin líneas.
        $id = ingresoCon([[1, 100000], [1, 100000]], ['descuento_documento_porcentaje' => 10]);

        $this->getJson("/api/ingresos/{$id}")
            ->assertJsonPath('descuento_documento_porcentaje', '10.00')
            ->assertJsonPath('descuento_documento', '20000.00')
            ->assertJsonPath('totales.total', '214200.00');

        $this->postJson("/api/ingresos/{$id}/confirmar")
            ->assertOk()
            ->assertJsonPath('descuento_documento', '20000.00')
            ->assertJsonPath('descuento_documento_porcentaje', null)
            ->assertJsonPath('total', '214200.00');

        expect(Ingreso::find($id)->descuento_documento)->toBe('20000.00');
    });

    it('S-22 (d): pasar de % a pesos en el borrador borra el %', function () {
        actuarComo(Rol::Logistica);
        $id = ingresoCon([[1, 100000]], ['descuento_documento_porcentaje' => 10]);

        $this->patchJson("/api/ingresos/{$id}", ['descuento_documento' => 5000])
            ->assertOk()
            ->assertJsonPath('descuento_documento', '5000.00')
            ->assertJsonPath('descuento_documento_porcentaje', null);
    });

    it('S-22 (e): enviar el descuento en % y en pesos juntos responde 422', function () {
        actuarComo(Rol::Logistica);

        $this->postJson('/api/ingresos', [
            'tipo' => 'factura', 'proveedor_id' => Proveedor::value('id'), 'numero_documento' => 'X1', 'fecha' => '2026-11-21',
            'descuento_documento' => 1000, 'descuento_documento_porcentaje' => 5,
        ])->assertUnprocessable()->assertJsonValidationErrors(['descuento_documento', 'descuento_documento_porcentaje']);
    });

    it('S-22 (e) / S-66: un descuento mayor que el subtotal neto se acepta en el borrador y responde 422 al confirmar', function () {
        actuarComo(Rol::Logistica);
        $id = ingresoCon([[1, 100000], [1, 50000]]);

        // S-86: sin descuento de línea (excluye al del documento). Subtotal neto 150.000. El borrador lo acepta y avisa.
        $this->patchJson("/api/ingresos/{$id}", ['descuento_documento' => 150000.01])
            ->assertOk()
            ->assertJsonPath('totales.descuento_excede_subtotal', true);
        $this->patchJson("/api/ingresos/{$id}", ['descuento_documento_porcentaje' => 100.01])->assertJsonValidationErrors('descuento_documento_porcentaje');

        $this->postJson("/api/ingresos/{$id}/confirmar")->assertStatus(422)->assertJsonValidationErrors('descuento_documento');

        // Quitar líneas puede dejar el descuento por encima: confirmar lo vuelve a revisar.
        $this->patchJson("/api/ingresos/{$id}", ['descuento_documento' => 100000])
            ->assertOk()
            ->assertJsonPath('totales.descuento_excede_subtotal', false);
        $mayor = IngresoItem::where('ingreso_id', $id)->orderByDesc('costo_unitario')->value('id');
        $this->deleteJson("/api/ingresos/{$id}/items/{$mayor}")->assertOk();

        $this->postJson("/api/ingresos/{$id}/confirmar")->assertStatus(422)->assertJsonValidationErrors('descuento_documento');
        expect(Ingreso::find($id)->estado->value)->toBe('borrador');
    });

    it('S-66: el descuento se fija con la factura vacía y los totales se recalculan al agregar, editar y quitar líneas', function () {
        actuarComo(Rol::Logistica);
        $id = ingresoCon([]);

        // Vacía: el descuento en % y en pesos se guardan.
        $this->patchJson("/api/ingresos/{$id}", ['descuento_documento_porcentaje' => 10])
            ->assertOk()
            ->assertJsonPath('descuento_documento_porcentaje', '10.00');
        $this->patchJson("/api/ingresos/{$id}", ['descuento_documento' => 5000])
            ->assertOk()
            ->assertJsonPath('descuento_documento', '5000.00')
            ->assertJsonPath('totales.descuento_excede_subtotal', true);

        // En % se recalcula con cada cambio de las líneas (IVA 0 para leer el total directo).
        $this->patchJson("/api/ingresos/{$id}", ['descuento_documento_porcentaje' => 10])->assertOk();
        $proveedor = Ingreso::find($id)->proveedor_id;
        $this->postJson("/api/ingresos/{$id}/items", [
            'referencia_id' => referenciaDePrueba("S51-{$id}", $proveedor)->id,
            'cantidad' => 1, 'costo_unitario' => 100000, 'descuento_porcentaje' => 0, 'iva_porcentaje' => 0,
        ])->assertOk();
        $linea = IngresoItem::where('ingreso_id', $id)->value('id');
        expect($this->getJson("/api/ingresos/{$id}")->json('totales.total'))->toBe('90000.00');

        $this->patchJson("/api/ingresos/{$id}/items/{$linea}", ['costo_unitario' => 200000])->assertOk();
        expect($this->getJson("/api/ingresos/{$id}")->json('totales'))
            ->toMatchArray(['descuento_documento' => '20000.00', 'total' => '180000.00', 'descuento_excede_subtotal' => false]);

        $this->deleteJson("/api/ingresos/{$id}/items/{$linea}")->assertOk();
        expect($this->getJson("/api/ingresos/{$id}")->json('totales.total'))->toBe('0.00');
    });

    it('S-22 (e): un descuento de línea mayor que 100 % o negativo responde 422', function () {
        actuarComo(Rol::Logistica);
        $id = ingresoCon([[1, 100000]]);
        $linea = IngresoItem::where('ingreso_id', $id)->value('id');

        $this->patchJson("/api/ingresos/{$id}/items/{$linea}", ['descuento_porcentaje' => 100.01])->assertJsonValidationErrors('descuento_porcentaje');
        $this->patchJson("/api/ingresos/{$id}/items/{$linea}", ['descuento_porcentaje' => -1])->assertJsonValidationErrors('descuento_porcentaje');
        $this->patchJson("/api/ingresos/{$id}/items/{$linea}", ['iva_porcentaje' => 101])->assertJsonValidationErrors('iva_porcentaje');
    });

    it('S-22 (g): una remisión con los mismos descuentos calcula igual que una factura', function () {
        actuarComo(Rol::Logistica);
        $lineas = [[1, 100000, 0, 19], [2, 30000, 0, 5], [1, 40000, 0, 0]]; // S-86: sin descuento de línea con descuento de documento

        $factura = $this->getJson('/api/ingresos/'.ingresoCon($lineas, ['descuento_documento_porcentaje' => 8], 'factura'))->json('totales');
        $remision = $this->getJson('/api/ingresos/'.ingresoCon($lineas, ['descuento_documento_porcentaje' => 8], 'remision'))->json('totales');

        expect($remision)->toBe($factura);
    });

    it('S-22: un documento en $0 se confirma de contado, pero no a crédito', function () {
        actuarComo(Rol::Logistica);
        $contado = ingresoCon([[1, 100000]], ['descuento_documento_porcentaje' => 100]);
        $credito = ingresoCon([[1, 100000]], ['descuento_documento_porcentaje' => 100, 'forma_pago' => 'credito', 'vence_el' => '2026-12-21']);

        $this->postJson("/api/ingresos/{$contado}/confirmar")->assertOk()->assertJsonPath('total', '0.00');
        $this->postJson("/api/ingresos/{$credito}/confirmar")->assertStatus(422);
        expect(Ingreso::find($credito)->estado->value)->toBe('borrador');
    });

    it('S-22: sin IVA explícito, la línea copia el IVA de venta de la referencia', function () {
        actuarComo(Rol::Logistica);
        $proveedor = Proveedor::orderBy('id')->value('id');
        $id = $this->postJson('/api/ingresos', ['tipo' => 'factura', 'proveedor_id' => $proveedor, 'numero_documento' => 'IVA-1', 'fecha' => '2026-11-21'])->json('id');

        $this->postJson("/api/ingresos/{$id}/items", ['referencia_id' => referenciaDePrueba('EXENTA', $proveedor, 0)->id, 'cantidad' => 1, 'costo_unitario' => 1000])
            ->assertJsonPath('items.0.iva_porcentaje', '0.00');
        $this->postJson("/api/ingresos/{$id}/escanear", ['codigo' => referenciaDePrueba('GRAVADA', $proveedor, 19)->codigo_referencia])
            ->assertJsonPath('iva_porcentaje', '19.00');
    });

    it('RF-11 / S-22: corregir el descuento y el IVA de una línea queda en la auditoría', function () {
        $logistica = actuarComo(Rol::Logistica);
        $id = ingresoCon([[1, 100000]]);
        $linea = IngresoItem::where('ingreso_id', $id)->value('id');

        $this->patchJson("/api/ingresos/{$id}/items/{$linea}", ['descuento_porcentaje' => 12.5, 'iva_porcentaje' => 5])->assertOk();

        $filas = Auditoria::where('tabla', 'ingreso_items')->where('registro_id', $linea)->get()->keyBy('campo');
        expect($filas['descuento_porcentaje']->valor_anterior)->toBe('0.00')
            ->and($filas['descuento_porcentaje']->valor_nuevo)->toBe('12.50')
            ->and($filas['iva_porcentaje']->valor_anterior)->toBe('19.00')
            ->and($filas['iva_porcentaje']->valor_nuevo)->toBe('5.00')
            ->and($filas['iva_porcentaje']->usuario_id)->toBe($logistica->id);
    });
});
