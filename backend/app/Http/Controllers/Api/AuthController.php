<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** Sanctum en modo SPA: sesión con cookies (GET /sanctum/csrf-cookie antes del login). */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credenciales = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->attempt([...$credenciales, 'activo' => true])) {
            throw ValidationException::withMessages(['email' => 'Credenciales inválidas o usuario inactivo.']);
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

        return response()->json(['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'rol' => $u->rol]);
    }
}
