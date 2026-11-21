<?php

namespace App\Enums;

/** RF-05 remisión digitada; RF-04 factura cuyo encabezado sale del QR (S-06). */
enum TipoIngreso: string
{
    case Remision = 'remision';
    case FacturaQr = 'factura_qr';

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
