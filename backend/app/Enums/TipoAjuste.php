<?php

namespace App\Enums;

/** RF-21 (S-27): una baja saca unidades del inventario; un reintegro corrige una baja hecha por error. */
enum TipoAjuste: string
{
    case Baja = 'baja';
    case Reintegro = 'reintegro';

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
