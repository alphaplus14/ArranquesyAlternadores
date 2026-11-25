<?php

namespace App\Support;

use App\Exceptions\ReglaInventarioException;
use App\Models\IngresoItem;

/**
 * RF-09 (ajuste) / RF-10 / S-21 / S-22: único lugar donde se calcula el total de un ingreso
 * (remisión o factura, igual).
 *
 *   bruto_i        = cantidad_i × costo_unitario_i
 *   neto_i         = bruto_i × (1 − descuento_porcentaje_i / 100)
 *   subtotal_neto  = Σ neto_i
 *   D              = descuento del documento en pesos (si se digitó en %, D = subtotal_neto × % / 100, al centavo)
 *   base_i         = neto_i × (1 − D / subtotal_neto)          (0 si subtotal_neto = 0)
 *   iva_i          = base_i × iva_porcentaje_i / 100            (sin redondear por línea; 0 % = sin IVA)
 *   total          = (subtotal_neto − D) + Σ iva_i, redondeado al peso al final
 *
 * Derivados solo para mostrar un borrador (S-66, ajuste): descuentoAplicado = min(D, subtotal_neto),
 * descuentoPendiente = max(0, D − subtotal_neto) y totalVisible = max(total, 0). No intervienen en
 * `total` ni en la regla de confirmar (exigirDescuentoValido).
 *
 * Todo con bcmath sobre cadenas decimales: nada de float. La regla de redondeo vive solo en
 * redondearAlPeso(), porque falta confirmarla con el contador.
 */
final class TotalesIngreso
{
    /** Cifras internas: de sobra para pesos con centavos y porcentajes. */
    private const ESCALA = 10;

    /**
     * @param  array<int, array{neto:string, base:string, iva:string}>  $lineas  por id de línea
     */
    private function __construct(
        public readonly string $subtotalBruto,
        public readonly string $descuentoLineas,
        public readonly string $subtotalNeto,
        public readonly string $descuentoDocumento,
        public readonly string $baseGravada,
        public readonly string $baseSinIva,
        public readonly string $iva,
        public readonly string $total,
        public readonly int $lineasSinIva,
        public readonly array $lineas,
        public readonly string $descuentoAplicado,
        public readonly string $descuentoPendiente,
        public readonly string $totalVisible,
    ) {}

    /**
     * @param  iterable<IngresoItem>  $items
     * @param  string|int|float|null  $descuentoPorcentaje  si no es null, manda sobre los pesos
     */
    public static function calcular(
        iterable $items,
        string|int|float|null $descuentoPesos,
        string|int|float|null $descuentoPorcentaje = null,
    ): self {
        $bruto = '0';
        $neto = '0';
        $sinIva = 0;
        $parciales = [];

        foreach ($items as $item) {
            $brutoLinea = bcmul((string) $item->cantidad, self::decimal($item->costo_unitario), self::ESCALA);
            $netoLinea = bcmul(
                $brutoLinea,
                bcsub('1', bcdiv(self::decimal($item->descuento_porcentaje), '100', self::ESCALA), self::ESCALA),
                self::ESCALA,
            );
            $ivaPorcentaje = self::decimal($item->iva_porcentaje);

            $parciales[] = [$item->id, $netoLinea, $ivaPorcentaje];
            $bruto = bcadd($bruto, $brutoLinea, self::ESCALA);
            $neto = bcadd($neto, $netoLinea, self::ESCALA);
            $sinIva += bccomp($ivaPorcentaje, '0', 2) === 0 ? 1 : 0;
        }

        $descuento = $descuentoPorcentaje === null
            ? self::decimal($descuentoPesos)
            : self::centavos(bcdiv(bcmul($neto, self::decimal($descuentoPorcentaje), self::ESCALA), '100', self::ESCALA));

        $hayNeto = bccomp($neto, '0', self::ESCALA) !== 0;
        $factor = $hayNeto ? bcsub('1', bcdiv($descuento, $neto, self::ESCALA), self::ESCALA) : '0';

        $iva = '0';
        $gravada = '0';
        $exenta = '0';
        $lineas = [];
        foreach ($parciales as [$id, $netoLinea, $ivaPorcentaje]) {
            $base = bcmul($netoLinea, $factor, self::ESCALA);
            $ivaLinea = bcdiv(bcmul($base, $ivaPorcentaje, self::ESCALA), '100', self::ESCALA);
            $iva = bcadd($iva, $ivaLinea, self::ESCALA);

            if (bccomp($ivaPorcentaje, '0', 2) === 0) {
                $exenta = bcadd($exenta, $base, self::ESCALA);
            } else {
                $gravada = bcadd($gravada, $base, self::ESCALA);
            }

            $lineas[$id] = ['neto' => self::centavos($netoLinea), 'base' => self::centavos($base), 'iva' => self::centavos($ivaLinea)];
        }

        $subtotalNeto = self::centavos($neto);
        $descuentoDocumento = self::centavos($descuento);
        $total = self::redondearAlPeso(bcadd(bcsub($neto, $descuento, self::ESCALA), $iva, self::ESCALA));

        return new self(
            subtotalBruto: self::centavos($bruto),
            descuentoLineas: self::centavos(bcsub($bruto, $neto, self::ESCALA)),
            subtotalNeto: $subtotalNeto,
            descuentoDocumento: $descuentoDocumento,
            baseGravada: self::centavos($gravada),
            baseSinIva: self::centavos($exenta),
            iva: self::redondearAlPeso($iva),
            total: $total,
            lineasSinIva: $sinIva,
            lineas: $lineas,
            // S-66 (ajuste): solo para mostrar el borrador; no cambian `total` ni la regla de confirmar.
            descuentoAplicado: bccomp($descuentoDocumento, $subtotalNeto, 2) === 1 ? $subtotalNeto : $descuentoDocumento,
            descuentoPendiente: bccomp($descuentoDocumento, $subtotalNeto, 2) === 1 ? bcsub($descuentoDocumento, $subtotalNeto, 2) : '0.00',
            totalVisible: bccomp($total, '0', 2) === -1 ? '0.00' : $total,
        );
    }

    /** RF-09: el descuento del documento no puede superar el subtotal neto de las líneas. */
    public function descuentoExcedeSubtotal(): bool
    {
        return bccomp($this->descuentoDocumento, $this->subtotalNeto, 2) === 1;
    }

    /** @throws ReglaInventarioException 422 si el descuento del documento supera el subtotal neto */
    public function exigirDescuentoValido(): void
    {
        if ($this->descuentoExcedeSubtotal()) {
            throw new ReglaInventarioException(
                "El descuento del documento ({$this->descuentoDocumento}) supera el subtotal neto de las líneas ({$this->subtotalNeto}).",
                422,
                ['errors' => ['descuento_documento' => ['El descuento del documento no puede superar el subtotal neto de las líneas.']]],
            );
        }
    }

    /**
     * Regla de redondeo del IVA y del total: al peso, mitad hacia arriba (lejos de cero).
     * Pendiente con el contador (S-21, S-22): si cambia, se cambia solo aquí.
     */
    public static function redondearAlPeso(string $valor): string
    {
        $mitad = str_starts_with($valor, '-') ? '-0.5' : '0.5';

        return bcadd(bcadd($valor, $mitad, 0), '0', 2);
    }

    /** Al centavo, mitad hacia arriba: cifras intermedias que se muestran y el descuento en % convertido a pesos. */
    private static function centavos(string $valor): string
    {
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
