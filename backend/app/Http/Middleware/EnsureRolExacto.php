<?php

namespace App\Http\Middleware;

use App\Enums\Rol;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * S-43: como `rol`, pero sin el pase del admin. Uso: ->middleware('solo_rol:mostrador') en lo que
 * solo hace ese rol (vender: abrir, escanear, cerrar, cambiar precios). Va después de `rol`, que ya
 * resolvió la sesión y el usuario inactivo; el 403 lleva el mismo mensaje.
 */
class EnsureRolExacto
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $usuario = $request->user();

        if (! $usuario || ! in_array($usuario->rol, array_map(Rol::from(...), $roles), true)) {
            abort(403, 'Su rol no tiene acceso a este módulo.');
        }

        return $next($request);
    }
}
