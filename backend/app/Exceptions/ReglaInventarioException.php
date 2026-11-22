<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Violación de una regla de negocio de inventario (unidad no disponible, venta cerrada…).
 * Se responde como JSON con el código HTTP indicado. $datos se agrega al cuerpo junto al mensaje,
 * para que el frontend distinga el caso sin leer el texto (por ejemplo, motivo e id del ingreso).
 *
 * @param  array<string, mixed>  $datos
 */
class ReglaInventarioException extends RuntimeException
{
    public function __construct(
        string $mensaje,
        public readonly int $status = 409,
        public readonly array $datos = [],
    ) {
        parent::__construct($mensaje);
    }
}
