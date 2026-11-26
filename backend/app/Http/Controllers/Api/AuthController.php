<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PreferenciasRequest;
use App\Models\User;
use App\Services\PreferenciasService;
use App\Rules\NombreUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Sanctum en modo SPA: sesión con cookies (GET /sanctum/csrf-cookie antes del login). */
class AuthController extends Controller
{
    public const MENSAJE_FALLIDO = 'Usuario o contraseña incorrectos.';

    /**
     * Hash bcrypt (costo 12, el de producción) de una clave que nadie usa. Se compara contra él
     * cuando el usuario no existe o está inactivo, para que esos casos tarden lo mismo que una
     * contraseña errónea y no se distingan por el tiempo de respuesta.
     */
    private const HASH_FALSO = '$2y$12$HWSTXAOSt0bALG8dvjBKNeEEqVHlIqvEZYCuySCkmJoktMnk74p.2';

    /**
     * Inicio de sesión con nombre de usuario (recortado y en minúsculas). Usuario inexistente,
     * contraseña errónea y cuenta inactiva responden exactamente igual: 422 y MENSAJE_FALLIDO.
     * El límite de intentos lo pone el rate limiter "login" (AppServiceProvider).
     */
    public function login(Request $request): JsonResponse
    {
        if ($request->has('usuario')) {
            $request->merge(['usuario' => NombreUsuario::normalizar($request->input('usuario'))]);
        }

        $credenciales = $request->validate([
            'usuario' => ['required', new NombreUsuario],
            'password' => ['required', 'string'],
            'email' => ['prohibited'],
        ], [
            'email.prohibited' => 'Inicie sesión con su nombre de usuario, no con el correo.',
        ]);

        $usuario = User::where('usuario', $credenciales['usuario'])->first();
        // Siempre se compara un hash, exista o no el usuario.
        $claveValida = Hash::check($credenciales['password'], $usuario?->password ?? self::HASH_FALSO);

        if (! $usuario || ! $usuario->activo || ! $claveValida) {
            throw ValidationException::withMessages(['usuario' => self::MENSAJE_FALLIDO]);
        }

        // Lo mismo que hacía Auth::attempt: rehash si cambió el costo, y abrir la sesión.
        $guardia = Auth::guard('web');
        $guardia->getProvider()->rehashPasswordIfRequired($usuario, ['password' => $credenciales['password']]);
        $guardia->login($usuario);

        $request->session()->regenerate();

        return $this->me($request);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    public function me(Request $request): JsonResponse
    {
        $u = $request->user();

        return response()->json([
            'id' => $u->id,
            'name' => $u->name,
            'usuario' => $u->usuario,
            'rol' => $u->rol,
            // S-70: viajan aquí (misma consulta del usuario, sin peticiones nuevas); {} para mostrador.
            'preferencias' => PreferenciasService::paraRespuesta($u),
        ]);
    }

    /** S-70: guarda las preferencias de pantalla (merge; null borra la clave). Sin auditoría. */
    public function preferencias(PreferenciasRequest $request, PreferenciasService $servicio): JsonResponse
    {
        return response()->json(['preferencias' => (object) $servicio->actualizar($request->user(), $request->validated())]);
    }
}
