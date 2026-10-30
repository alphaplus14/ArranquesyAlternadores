<?php

namespace App\Http\Middleware;

use App\Enums\Rol;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Regla 12: cada endpoint queda protegido por rol. Uso: ->middleware('rol:logistica,mostrador').
 * El admin pasa siempre (ver User::tieneRol). Un usuario inactivo pierde la sesión.
 */
class EnsureRol
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $usuario = $request->user();

        if (! $usuario || ! $usuario->activo) {
            Auth::guard('web')->logout();

            abort(401, 'Usuario inactivo o sin sesión.');
        }

        if (! $usuario->tieneRol(...array_map(Rol::from(...), $roles))) {
            abort(403, 'Su rol no tiene acceso a este módulo.');
        }

        return $next($request);
    }
}
