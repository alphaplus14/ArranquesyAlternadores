<?php

namespace App\Enums;

/** RF-27: préstamo del local al empleado (suma al saldo) o abono del empleado (lo resta). */
enum TipoMovimientoEmpleado: string
{
    case Prestamo = 'prestamo';
    case Abono = 'abono';
}
