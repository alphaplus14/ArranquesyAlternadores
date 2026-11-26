<?php

namespace App\Support;

use App\Models\Ingreso;
use App\Models\IngresoItem;

/**
 * S-45 b: costo efectivo de una unidad. Único lugar con esta regla.
 *
 *   costo_unidad = base de su línea de ingreso ÷ cantidad de la línea
 *
 * La base sale de TotalesIngreso (descuento de línea y descuento del documento prorrateado, sin
 * IVA); aquí no se repite esa fórmula. Una unidad sin línea de ingreso (alta por ajuste, S-39) no
 * tiene costo: se cuenta aparte como "sin costo". El costo de una remisión es provisional y no se
 * reajusta con la factura que la cubre (adenda, sección 6).
 *
 * Cifras en cadenas decimales con bcmath.
 */
final class CostoEfectivo
{
    private const ESCALA = 10;

    /**
     * Costo por unidad de cada línea pedida (y de las demás líneas de sus ingresos, que salen en el
     * mismo cálculo). Tres consultas, sin importar cuántas líneas.
     *
     * @param  iterable<int>  $lineaIds  ids de ingreso_items
     * @return array<int, string> costo por unidad, sin redondear, por id de línea
     */
    public static function deLineas(iterable $lineaIds): array
    {
        $ids = collect($lineaIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $ingresoIds = IngresoItem::query()->whereKey($ids)->distinct()->pluck('ingreso_id');
        $descuentos = Ingreso::query()->whereKey($ingresoIds)->pluck('descuento_documento', 'id');
        $items = IngresoItem::query()->whereIn('ingreso_id', $ingresoIds)->get()->groupBy('ingreso_id');

        return self::deIngresos($items, $descuentos->all());
    }

    /**
     * La regla sobre líneas ya cargadas, agrupadas por ingreso (todas las del ingreso: el
     * descuento del documento se prorratea entre ellas). Sirve con modelos o con filas planas
     * (id, cantidad, costo_unitario, descuento_porcentaje, iva_porcentaje). S-71 la usa con una
     * sola consulta para el último costo del listado de Inventario.
     *
     * @param  iterable<int|string, iterable<object>>  $itemsPorIngreso
     * @param  array<int|string, string|int|float|null>  $descuentos  descuento del documento en pesos, por ingreso
     * @return array<int, string> costo por unidad, sin redondear, por id de línea
     */
    public static function deIngresos(iterable $itemsPorIngreso, array $descuentos): array
    {
        $costos = [];
        foreach ($itemsPorIngreso as $ingresoId => $lineas) {
            // Confirmado, el descuento del documento queda en pesos (IngresoService::confirmar).
            $totales = TotalesIngreso::calcular($lineas, $descuentos[$ingresoId] ?? 0);
            foreach ($lineas as $linea) {
                if ($linea->cantidad > 0 && isset($totales->lineas[$linea->id])) {
                    $costos[(int) $linea->id] = bcdiv($totales->lineas[$linea->id]['base'], (string) $linea->cantidad, self::ESCALA);
                }
            }
        }

        return $costos;
    }

    /**
     * Costo total de unidades contadas por línea, redondeado al peso una sola vez (como TotalesIngreso).
     *
     * @param  iterable<array{linea: int|null, unidades: int}>  $conteos
     * @param  array<int, string>  $costos  de deLineas()
     * @return array{costo: string, con_costo: int, sin_costo: int}
     */
    public static function sumar(iterable $conteos, array $costos): array
    {
        $costo = '0';
        $con = 0;
        $sin = 0;
        foreach ($conteos as $c) {
            $linea = $c['linea'];
            if ($linea !== null && isset($costos[$linea])) {
                $costo = bcadd($costo, bcmul($costos[$linea], (string) $c['unidades'], self::ESCALA), self::ESCALA);
                $con += $c['unidades'];
            } else {
                $sin += $c['unidades'];
            }
        }

        return ['costo' => TotalesIngreso::redondearAlPeso($costo), 'con_costo' => $con, 'sin_costo' => $sin];
    }
}
