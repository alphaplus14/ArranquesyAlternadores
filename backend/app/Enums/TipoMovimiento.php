<?php

namespace App\Enums;

/** Historial inmutable de inventario. RF-21: baja y reintegro por ajuste de logística. */
enum TipoMovimiento: string
{
    case Ingreso = 'ingreso';
    case Venta = 'venta';
    case Reverso = 'reverso';
    case Baja = 'baja';
    case Reintegro = 'reintegro';

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
