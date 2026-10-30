<?php

namespace App\Enums;

/** RF-05 remisión; RF-04 factura_xml queda preparado, sin implementar (S-06). */
enum TipoIngreso: string
{
    case Remision = 'remision';
    case FacturaXml = 'factura_xml';

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
