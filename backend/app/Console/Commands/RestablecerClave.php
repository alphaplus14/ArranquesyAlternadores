<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\UsuarioService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Las cuentas admin están protegidas en la aplicación: si un admin olvida su contraseña,
 * el técnico la restablece aquí, con acceso al servidor. Queda en la auditoría sin usuario.
 */
class RestablecerClave extends Command
{
    protected $signature = 'usuarios:clave {email : Correo de la cuenta}';

    protected $description = 'Restablece la contraseña de una cuenta (pensado para administradores)';

    public function handle(UsuarioService $usuarios): int
    {
        $usuario = User::where('email', $this->argument('email'))->first();

        if (! $usuario) {
            $this->error('No existe una cuenta con ese correo.');

            return self::FAILURE;
        }

        $clave = (string) $this->secret('Contraseña nueva');
        $confirmacion = (string) $this->secret('Repita la contraseña');

        $validacion = Validator::make(
            ['password' => $clave, 'password_confirmation' => $confirmacion],
            ['password' => ['required', 'confirmed', Password::min(8)]],
        );

        if ($validacion->fails()) {
            $this->error($validacion->errors()->first('password'));

            return self::FAILURE;
        }

        $usuarios->restablecerClave($usuario, $clave);
        $this->info("Contraseña restablecida para {$usuario->email}. Sus sesiones abiertas se cerraron.");

        return self::SUCCESS;
    }
}
