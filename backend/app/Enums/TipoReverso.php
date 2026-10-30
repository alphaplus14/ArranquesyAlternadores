<?php

namespace App\Enums;

/** RF-07 */
enum TipoReverso: string
{
    case Devolucion = 'devolucion';
    case Anulacion = 'anulacion';

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
