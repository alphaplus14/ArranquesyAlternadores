<?php

namespace App\Enums;

/** RF-05 */
enum EstadoIngreso: string
{
    case Borrador = 'borrador';
    case Confirmado = 'confirmado';

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
