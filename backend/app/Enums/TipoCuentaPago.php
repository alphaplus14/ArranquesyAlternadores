<?php

namespace App\Enums;

/** RF-24 (S-48): tipo de una cuenta de pago. Decide el `medio` de abonos y pagos y la columna del cuadre mensual. */
enum TipoCuentaPago: string
{
    case Efectivo = 'efectivo';
    case Banco = 'banco';
    case Datafono = 'datafono';
    case Billetera = 'billetera';

    /** S-48: medio que se guarda en abonos y pagos: efectivo → 'efectivo'; lo demás → 'transferencia'. */
    public function medio(): string
    {
        return $this === self::Efectivo ? 'efectivo' : 'transferencia';
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
