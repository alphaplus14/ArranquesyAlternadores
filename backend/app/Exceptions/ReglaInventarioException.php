<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Violación de una regla de negocio de inventario (unidad no disponible, venta cerrada…).
 * Se responde como JSON con el código HTTP indicado.
 */
class ReglaInventarioException extends RuntimeException
{
    public function __construct(string $mensaje, public readonly int $status = 409)
    {
        parent::__construct($mensaje);
    }
}
