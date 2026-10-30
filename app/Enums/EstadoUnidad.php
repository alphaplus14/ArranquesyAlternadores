<?php

namespace App\Enums;

/** RF-03: estado de cada unidad física. */
enum EstadoUnidad: string
{
    case Disponible = 'disponible';
    case Vendida = 'vendida';

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
