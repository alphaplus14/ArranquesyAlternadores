<?php

namespace App\Enums;

/** RF-06. S-46: `descartada` solo la pone el sistema a una venta abierta sin ítems vigentes. */
enum EstadoVenta: string
{
    case Abierta = 'abierta';
    case Cerrada = 'cerrada';
    case Descartada = 'descartada';

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
