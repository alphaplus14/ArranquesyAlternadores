<?php

namespace App\Enums;

/** RF-14: el descargue puede ser de contado o a crédito (regla 7). */
enum FormaPago: string
{
    case Contado = 'contado';
    case Credito = 'credito';
}
