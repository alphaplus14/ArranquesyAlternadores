<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Formato del nombre de usuario con que se inicia sesión. Único lugar donde vive la regla:
 * 3 a 30 caracteres, solo minúsculas, números, punto, guion y guion bajo.
 * El valor se normaliza (recortado y en minúsculas) antes de validar, al guardar y al iniciar sesión.
 */
class NombreUsuario implements ValidationRule
{
    public const MIN = 3;

    public const MAX = 30;

    public const PATRON = '/^[a-z0-9._-]+$/';

    public static function normalizar(mixed $valor): mixed
    {
        return is_string($valor) ? mb_strtolower(trim($valor)) : $valor;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('El usuario debe ser texto.');

            return;
        }

        if (str_contains($value, '@')) {
            $fail('Escriba su nombre de usuario, no el correo.');

            return;
        }

        $largo = mb_strlen($value);

        if ($largo < self::MIN || $largo > self::MAX) {
            $fail('El usuario debe tener entre '.self::MIN.' y '.self::MAX.' caracteres.');

            return;
        }

        if (! preg_match(self::PATRON, $value)) {
            $fail('El usuario solo admite letras minúsculas, números, punto, guion y guion bajo.');
        }
    }
}
