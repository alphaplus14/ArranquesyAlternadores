<?php

use App\Exceptions\RegistroInmutableException;
use App\Exceptions\ReglaInventarioException;
use App\Http\Middleware\EnsureRol;
use App\Http\Middleware\MedirDuracion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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

        $exceptions->render(fn (ReglaInventarioException $e) => response()->json(['message' => $e->getMessage()], $e->status));

        $exceptions->render(fn (RegistroInmutableException $e) => response()->json(['message' => $e->getMessage()], 409));

        // FK restrict: un catálogo con dependencias no se borra.
        $exceptions->render(function (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1451) {
                return response()->json(['message' => 'El registro tiene datos asociados y no se puede borrar.'], 409);
            }
        });
    })->create();
