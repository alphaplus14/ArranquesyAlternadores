<?php

use App\Exceptions\RegistroInmutableException;
use App\Exceptions\ReglaInventarioException;
use App\Http\Middleware\EnsureRol;
use App\Http\Middleware\MedirDuracion;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lo que ve la pantalla nunca nombra una ruta, una clase ni una consulta.
 * Los mensajes de negocio, ya en español, se dejan igual.
 */
if (! function_exists('mensajeVisibleApi')) {
function mensajeVisibleApi(string $mensaje, int $estado): string
{
    if (preg_match('/No query results for model \[App\\\\Models\\\\(\w+)\]/', $mensaje, $coincidencia)) {
        return 'No se encontró '.nombreDeModelo($coincidencia[1]).'.';
    }

    $tecnico = preg_match(
        '/could not be found|is not supported for route|SQLSTATE|App\\\\|Illuminate\\\\|Symfony\\\\|vendor[\/\\\\]|Stack trace|Unauthenticated\.?$|^Too Many Attempts\.?$|^Server Error\.?$|Target class /',
        $mensaje,
    ) === 1;

    if (! $tecnico) {
        return $mensaje;
    }

    return match ($estado) {
        401 => 'Debe iniciar sesión.',
        403 => 'No tiene permiso para esta acción.',
        404 => 'No se encontró lo que buscaba.',
        405 => 'Esa acción no está disponible.',
        429 => 'Demasiados intentos. Espere un momento e intente de nuevo.',
        default => 'No se pudo completar la operación.',
    };
}

function nombreDeModelo(string $clase): string
{
    return match ($clase) {
        'Ingreso' => 'el ingreso',
        'IngresoItem' => 'la línea del ingreso',
        'Referencia' => 'la referencia',
        'Unidad' => 'la unidad',
        'Venta' => 'la venta',
        'VentaItem' => 'el ítem de la venta',
        'Cliente' => 'el cliente',
        'Proveedor' => 'el proveedor',
        'User' => 'el usuario',
        'CodigoUniversal' => 'el código universal',
        'Categoria' => 'la categoría',
        'Reverso', 'ReversoItem' => 'el reverso',
        'MovimientoInventario' => 'el movimiento',
        'Auditoria' => 'el registro de auditoría',
        default => 'el registro',
    };
}
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum en modo SPA: sesión con cookies para el frontend en SANCTUM_STATEFUL_DOMAINS.
        $middleware->statefulApi();

        $middleware->alias([
            'rol' => EnsureRol::class,
            'medir' => MedirDuracion::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(fn (ReglaInventarioException $e) => response()->json(['message' => $e->getMessage(), ...$e->datos], $e->status));

        $exceptions->render(fn (RegistroInmutableException $e) => response()->json(['message' => $e->getMessage()], 409));

        // Un id que no existe no debe mostrar "App\Models\..." en la pantalla.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            $modelo = $e->getPrevious();
            if (! $modelo instanceof ModelNotFoundException || (! $request->is('api/*') && ! $request->expectsJson())) {
                return null;
            }

            $nombre = nombreDeModelo(class_basename($modelo->getModel()));

            return response()->json(['message' => "No se encontró {$nombre}."], 404);
        });

        // Última revisión: ni el mensaje ni el volcado de depuración salen con rutas o clases.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if ((! $request->is('api/*') && ! $request->expectsJson()) || ! $response instanceof JsonResponse) {
                return $response;
            }

            $datos = $response->getData(true);
            if (! is_array($datos)) {
                return $response;
            }

            if (isset($datos['message']) && is_string($datos['message'])) {
                $datos['message'] = mensajeVisibleApi($datos['message'], $response->getStatusCode());
            }

            unset($datos['exception'], $datos['file'], $datos['line'], $datos['trace']);
            $response->setData($datos);

            return $response;
        });

        // FK restrict: un catálogo con dependencias no se borra.
        $exceptions->render(function (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1451) {
                return response()->json(['message' => 'El registro tiene datos asociados y no se puede borrar.'], 409);
            }
        });
    })->create();
