<?php

namespace App\Support;

use App\Exceptions\ReglaInventarioException;
use App\Models\IngresoItem;

/**
 * RF-09 (ajuste) / RF-10 / S-21: único lugar donde se calcula el total de un ingreso.
 *
 *   subtotal_i = cantidad_i × costo_unitario_i − descuento de la línea
 *   subtotal   = Σ subtotal_i
 *   factor     = 1 − descuento_documento / subtotal      (1 si subtotal = 0)
 *   base_i     = subtotal_i × factor
 *   iva        = Σ base_i × iva_porcentaje_i / 100      (sin redondear por línea; sin IVA aporta 0)
 *   total      = (subtotal − descuento_documento) + iva, redondeado al peso al final
 *
 * Todo con bcmath sobre cadenas decimales: nada de float. La regla de redondeo vive solo en
 * redondearAlPeso(), porque falta confirmarla con el contador.
 */
final class TotalesIngreso
{
    /** Cifras internas: de sobra para pesos con centavos y porcentajes. */
    private const ESCALA = 10;

    /**
     * @param  array<int, array{subtotal:string, base:string, iva:string}>  $lineas  por id de línea
     */
    private function __construct(
        public readonly string $subtotal,
        public readonly string $descuentoLineas,
        public readonly string $descuentoDocumento,
        public readonly string $base,
        public readonly string $iva,
        public readonly string $total,
        public readonly int $lineasSinIva,
        public readonly array $lineas,
    ) {}

    /** @param iterable<IngresoItem> $items */
    public static function calcular(iterable $items, string|int|float|null $descuentoDocumento): self
    {
        $descuentoDocumento = self::decimal($descuentoDocumento);
        $subtotal = '0';
        $descuentoLineas = '0';
        $sinIva = 0;
        $parciales = [];

        foreach ($items as $item) {
            $bruto = bcmul((string) $item->cantidad, self::decimal($item->costo_unitario), self::ESCALA);
            $descuento = self::decimal($item->descuento);
            $parcial = bcsub($bruto, $descuento, self::ESCALA);

            $parciales[] = [$item, $parcial];
            $subtotal = bcadd($subtotal, $parcial, self::ESCALA);
            $descuentoLineas = bcadd($descuentoLineas, $descuento, self::ESCALA);
            $sinIva += $item->sin_iva ? 1 : 0;
        }

        $factor = bccomp($subtotal, '0', self::ESCALA) === 0
            ? '1'
            : bcsub('1', bcdiv($descuentoDocumento, $subtotal, self::ESCALA), self::ESCALA);

        $iva = '0';
        $lineas = [];
        foreach ($parciales as [$item, $parcial]) {
            $base = bcmul($parcial, $factor, self::ESCALA);
            $ivaLinea = $item->sin_iva
                ? '0'
                : bcdiv(bcmul($base, self::decimal($item->iva_porcentaje), self::ESCALA), '100', self::ESCALA);
            $iva = bcadd($iva, $ivaLinea, self::ESCALA);

            $lineas[$item->id] = [
                'subtotal' => self::dosDecimales($parcial),
                'base' => self::dosDecimales($base),
                'iva' => self::dosDecimales($ivaLinea),
            ];
        }

        $base = bcsub($subtotal, $descuentoDocumento, self::ESCALA);

        return new self(
            subtotal: self::dosDecimales($subtotal),
            descuentoLineas: self::dosDecimales($descuentoLineas),
            descuentoDocumento: self::dosDecimales($descuentoDocumento),
            base: self::dosDecimales($base),
            iva: self::redondearAlPeso($iva),
            total: self::redondearAlPeso(bcadd($base, $iva, self::ESCALA)),
            lineasSinIva: $sinIva,
            lineas: $lineas,
        );
    }

    /** RF-09: el descuento del documento no puede superar el subtotal de las líneas. */
    public function descuentoExcedeSubtotal(): bool
    {
        return bccomp($this->descuentoDocumento, $this->subtotal, 2) === 1;
    }

    /** @throws ReglaInventarioException 422 si el descuento del documento supera el subtotal */
    public function exigirDescuentoValido(): void
    {
        if ($this->descuentoExcedeSubtotal()) {
            throw new ReglaInventarioException(
                "El descuento del documento ({$this->descuentoDocumento}) supera el subtotal de las líneas ({$this->subtotal}).",
                422,
                ['errors' => ['descuento_total' => ['El descuento del documento no puede superar el subtotal de las líneas.']]],
            );
        }
    }

    /**
     * Regla de redondeo del IVA y del total: al peso, mitad hacia arriba (lejos de cero).
     * Pendiente con el contador (S-21): si cambia, se cambia solo aquí.
     */
    public static function redondearAlPeso(string $valor): string
    {
        $mitad = str_starts_with($valor, '-') ? '-0.5' : '0.5';

        return bcadd(bcadd($valor, $mitad, 0), '0', 2);
    }

    private static function dosDecimales(string $valor): string
    {
        // Redondeo a centavos solo para mostrar cifras intermedias; no entra en el total.
        $mitad = str_starts_with($valor, '-') ? '-0.005' : '0.005';

        return bcadd($valor, $mitad, 2);
    }

    private static function decimal(string|int|float|null $valor): string
    {
        if ($valor === null || $valor === '') {
            return '0';
        }

        // Los casts decimal de Laravel ya entregan cadenas; un número suelto se pasa sin notación científica.
        return is_string($valor) ? $valor : number_format((float) $valor, 2, '.', '');
    }
}
