<?php

namespace App\Exceptions;

use LogicException;

/** RF-07 / RF-11: ventas, reversos, movimientos y auditoría no se editan ni se borran. */
class RegistroInmutableException extends LogicException
{
    public static function para(object $modelo, string $accion): self
    {
        return new self(sprintf('%s es inmutable: no se permite %s.', class_basename($modelo), $accion));
    }
}
