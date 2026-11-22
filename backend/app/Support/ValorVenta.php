<?php

namespace App\Support;

/**
 * RF-14 / RF-18: único lugar donde se calcula el valor de una venta a partir de sus ítems.
 *
 * El precio de venta incluye el IVA (S-24): el cliente debe la suma de los precios. La base y el
 * IVA se sacan de ese precio con el IVA guardado en cada ítem:
 *
 *   total  = Σ precio_unitario_i
 *   base   = Σ precio_unitario_i / (1 + iva_porcentaje_i / 100), redondeada al peso al final
 *   iva    = total − base
 *
 * Los ítems sin precio (ventas anteriores a RF-14) no suman.
 */
final class ValorVenta
{
    private const ESCALA = 10;

    private function __construct(
        public readonly string $total,
        public readonly string $base,
        public readonly string $iva,
    ) {}

    /**
     * @param  iterable<object{precio_unitario:?string, iva_porcentaje:?string}|array{precio_unitario:?string, iva_porcentaje:?string}>  $items
     */
    public static function calcular(iterable $items): self
    {
        $total = '0';
        $base = '0';

        foreach ($items as $item) {
            $precio = is_array($item) ? $item['precio_unitario'] : $item->precio_unitario;
            if ($precio === null) {
                continue;
            }

            $iva = (is_array($item) ? $item['iva_porcentaje'] : $item->iva_porcentaje) ?? '0';
            $divisor = bcadd('1', bcdiv((string) $iva, '100', self::ESCALA), self::ESCALA);

            $total = bcadd($total, (string) $precio, self::ESCALA);
            $base = bcadd($base, bcdiv((string) $precio, $divisor, self::ESCALA), self::ESCALA);
        }

        $total = bcadd($total, '0', 2);
        $base = TotalesIngreso::redondearAlPeso($base);

        return new self($total, $base, bcsub($total, $base, 2));
    }
}
