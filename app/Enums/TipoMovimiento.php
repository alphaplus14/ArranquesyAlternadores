<?php

namespace App\Enums;

/** Historial inmutable de inventario. */
enum TipoMovimiento: string
{
    case Ingreso = 'ingreso';
    case Venta = 'venta';
    case Reverso = 'reverso';

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
