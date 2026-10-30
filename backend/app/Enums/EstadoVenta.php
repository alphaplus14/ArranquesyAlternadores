<?php

namespace App\Enums;

/** RF-06 */
enum EstadoVenta: string
{
    case Abierta = 'abierta';
    case Cerrada = 'cerrada';

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
