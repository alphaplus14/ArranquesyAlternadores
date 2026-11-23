<?php

namespace App\Enums;

/** RF-03: estado de cada unidad física. RF-21: `retirada` = salió del inventario por una baja. */
enum EstadoUnidad: string
{
    case Disponible = 'disponible';
    case Vendida = 'vendida';
    case Retirada = 'retirada';

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
