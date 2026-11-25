<?php

namespace App\Enums;

/**
 * RF-27 (S-55): cómo paga un empleado su abono. `caja` entra a una cuenta de pago (obligatoria);
 * `nomina` solo registra que se descontó de su pago: no mueve caja ni calcula nómina.
 */
enum FormaAbonoEmpleado: string
{
    case Caja = 'caja';
    case Nomina = 'nomina';
}
