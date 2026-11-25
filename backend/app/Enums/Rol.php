<?php

namespace App\Enums;

/** Roles del sistema: un módulo por rol (RF-06, sección 4 del documento). */
enum Rol: string
{
    case Admin = 'admin';
    case Logistica = 'logistica';
    case Mostrador = 'mostrador';
    // RF-29: auxiliar contable, acotado a cartera, cuentas por pagar, pagos e informes.
    case Contable = 'contable';

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
