<?php

namespace App\Enums;

/**
 * RF-21 (S-27): una baja saca unidades del inventario; un reintegro corrige una baja hecha por error.
 * S-39: un alta crea unidades nuevas que aparecieron en un inventario físico (no es una compra).
 */
enum TipoAjuste: string
{
    case Baja = 'baja';
    case Reintegro = 'reintegro';
    case Alta = 'alta';

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
