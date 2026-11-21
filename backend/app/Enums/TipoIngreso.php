<?php

namespace App\Enums;

/** RF-05 remisión y RF-04 factura de compra: las dos se ingresan a mano (sin QR ni XML). */
enum TipoIngreso: string
{
    case Remision = 'remision';
    case Factura = 'factura';

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
