<?php

namespace App\Services;

use App\Enums\Rol;
use App\Exceptions\ReglaInventarioException;
use App\Models\User;
use Illuminate\Support\Arr;

/**
 * Cuentas de usuario. Regla 12: el admin gestiona a los empleados (logística y mostrador).
 * Las cuentas admin están protegidas: ningún admin edita a otro, y cada uno edita la suya
 * desde su cuenta (nombre, correo, contraseña), nunca su rol ni su estado.
 * Cambiar la contraseña cierra las otras sesiones del usuario: AuthenticateSession de Sanctum
 * compara el hash guardado en cada sesión y la sesión que hizo el cambio guarda el nuevo.
 */
class UsuarioService
{
    public function crear(array $datos): User
    {
        return User::create($datos);
    }

    public function actualizar(User $usuario, array $datos): User
    {
        if ($usuario->rol === Rol::Admin) {
            throw new ReglaInventarioException('Las cuentas de administrador están protegidas: cada administrador edita la suya desde su cuenta.', 403);
        }

        if (($datos['rol'] ?? null) === Rol::Admin->value) {
            throw new ReglaInventarioException('Un empleado no se asciende a administrador: cree una cuenta nueva con ese rol.', 422);
        }

        $usuario->update($datos);

        return $usuario;
    }

    public function actualizarCuenta(User $usuario, array $datos): User
    {
        $usuario->update(Arr::only($datos, ['name', 'email', 'password']));

        return $usuario;
    }

    public function restablecerClave(User $usuario, string $clave): void
    {
        $usuario->update(['password' => $clave]);
    }
}
