<?php

namespace App\Enums;

/** RF-12: cómo llegó el código al sistema. */
enum MetodoCaptura: string
{
    case Escaneado = 'escaneado';
    case Digitado = 'digitado';

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
