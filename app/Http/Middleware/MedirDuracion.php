<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Supuesto S-04: registra la duración de los endpoints críticos en storage/logs/rendimiento-*.log.
 * Uso: ->middleware('medir:300') con el objetivo en milisegundos; si se pasa, se registra como warning.
 */
class MedirDuracion
{
    public function handle(Request $request, Closure $next, string $objetivoMs = '500'): Response
    {
        $inicio = hrtime(true);

        $respuesta = $next($request);

        $ms = round((hrtime(true) - $inicio) / 1e6, 1);
        $contexto = [
            'ruta' => $request->route()?->getName() ?? $request->path(),
            'ms' => $ms,
            'objetivo_ms' => (int) $objetivoMs,
            'status' => $respuesta->getStatusCode(),
            'usuario_id' => $request->user()?->id,
        ];

        Log::channel('rendimiento')->log($ms > (int) $objetivoMs ? 'warning' : 'info', 'duracion', $contexto);

        $respuesta->headers->set('Server-Timing', "app;dur={$ms}");

        return $respuesta;
    }
}
