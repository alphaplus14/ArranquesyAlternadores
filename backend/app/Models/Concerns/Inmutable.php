<?php

namespace App\Models\Concerns;

use App\Exceptions\RegistroInmutableException;

/**
 * RF-07 / RF-11: el registro se crea una vez y no se edita ni se borra.
 */
trait Inmutable
{
    public static function bootInmutable(): void
    {
        static::updating(function ($modelo) {
            throw RegistroInmutableException::para($modelo, 'editar');
        });

        static::deleting(function ($modelo) {
            throw RegistroInmutableException::para($modelo, 'borrar');
        });
    }
}
