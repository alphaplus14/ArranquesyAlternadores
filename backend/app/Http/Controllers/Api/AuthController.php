<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Rules\NombreUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** Sanctum en modo SPA: sesión con cookies (GET /sanctum/csrf-cookie antes del login). */
class AuthController extends Controller
{
    /**
     * Inicio de sesión con nombre de usuario (recortado y en minúsculas). Ante cualquier fallo
     * (usuario inexistente, contraseña errónea o cuenta inactiva) el mensaje es el mismo.
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

        $credenciales = Arr::only($credenciales, ['usuario', 'password']);

        if (! Auth::guard('web')->attempt([...$credenciales, 'activo' => true])) {
            throw ValidationException::withMessages(['usuario' => 'Usuario o contraseña incorrectos, o usuario inactivo.']);
        }

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

        return response()->json(['id' => $u->id, 'name' => $u->name, 'usuario' => $u->usuario, 'rol' => $u->rol]);
    }
}
