<?php

namespace App\Support;

/**
 * S-33 / S-15: desglose del total de una venta de mostrador. Único lugar con este cálculo.
 *
 * Los precios de venta (referencias.precio_venta y venta_items.precio_unitario) INCLUYEN el IVA:
 * - Total = suma de precio_unitario de los ítems vigentes (no reversados). No se le suma IVA.
 * - Base de cada ítem = precio_unitario / (1 + iva_porcentaje / 100), sin redondear.
 * - Base total = suma de bases redondeada al peso una sola vez, al final; IVA incluido = total − base.
 * - Un ítem antiguo sin iva_porcentaje (NULL) no se desglosa: cuenta como 0 % solo aquí.
 *
 * Compras es distinto (RF-09, TotalesIngreso): allí el costo es antes de IVA y el IVA se suma.
 * Cómo redondea Contapyme el IVA de venta está pendiente con el contador: unos pesos de diferencia
 * con la factura son esperables.
 */
final class TotalesVenta
{
    private const ESCALA = 10;

    /**
     * @param  list<int|float|string>  $porcentajes  porcentajes de IVA distintos de los ítems vigentes desglosados
     */
    private function __construct(
        public readonly string $total,
        public readonly string $base,
        public readonly string $ivaIncluido,
        public readonly array $porcentajes,
        public readonly int $itemsSinIva,
    ) {}

    /**
     * @param  iterable<array{precio: string|int|float|null, iva: string|int|float|null, vigente: bool}>  $items
     */
    public static function desglosar(iterable $items): self
    {
        $total = '0';
        $base = '0';
        $porcentajes = [];
        $sinIva = 0;

        foreach ($items as $item) {
            if (! $item['vigente'] || $item['precio'] === null) {
                continue;
            }

            $precio = self::decimal($item['precio']);
            $total = bcadd($total, $precio, self::ESCALA);

            if ($item['iva'] === null) {
                $sinIva++;
                $base = bcadd($base, $precio, self::ESCALA);

                continue;
            }

            $iva = self::decimal($item['iva']);
            $porcentajes[bcadd($iva, '0', 2)] = true;
            $divisor = bcadd('1', bcdiv($iva, '100', self::ESCALA), self::ESCALA);
            $base = bcadd($base, bcdiv($precio, $divisor, self::ESCALA), self::ESCALA);
        }

        $total = bcadd($total, '0', 2);
        $baseTotal = TotalesIngreso::redondearAlPeso($base);

        return new self(
            total: $total,
            base: $baseTotal,
            ivaIncluido: bcsub($total, $baseTotal, 2),
            porcentajes: array_map('floatval', array_keys($porcentajes)),
            itemsSinIva: $sinIva,
        );
    }

    /** @return array{total: string, base: string, iva_incluido: string, iva_porcentajes: list<float>, items_sin_iva: int} */
    public function aArreglo(): array
    {
        return [
            'total' => $this->total,
            'base' => $this->base,
            'iva_incluido' => $this->ivaIncluido,
            'iva_porcentajes' => $this->porcentajes,
            'items_sin_iva' => $this->itemsSinIva,
        ];
    }

    private static function decimal(string|int|float $valor): string
    {
        return is_string($valor) ? $valor : number_format((float) $valor, 2, '.', '');
    }
}
