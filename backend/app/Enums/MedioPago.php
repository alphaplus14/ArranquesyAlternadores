<?php

namespace App\Enums;

/** RF-15 (S-30): medio con que el cliente paga un abono. */
enum MedioPago: string
{
    case Efectivo = 'efectivo';
    case Transferencia = 'transferencia';
    case Consignacion = 'consignacion';
    case Otro = 'otro';

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
