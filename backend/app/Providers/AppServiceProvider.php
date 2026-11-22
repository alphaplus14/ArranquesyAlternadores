<?php

namespace App\Providers;

use App\Models\Ingreso;
use App\Models\Reverso;
use App\Models\Venta;
use App\Rules\NombreUsuario;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Origen de movimientos_inventario con nombres cortos y estables.
        Relation::enforceMorphMap([
            'ingreso' => Ingreso::class,
            'venta' => Venta::class,
            'reverso' => Reverso::class,
        ]);

        // Velocidad (regla 13): un N+1 falla en desarrollo y tests en vez de pasar desapercibido.
        Model::preventLazyLoading(! $this->app->isProduction());

        JsonResource::withoutWrapping();

        // Login: 5 intentos por minuto por usuario + IP. Al pasarse, 429 con los segundos que faltan.
        RateLimiter::for('login', function (Request $request) {
            $usuario = NombreUsuario::normalizar($request->input('usuario'));

            return Limit::perMinute(5)
                ->by((is_string($usuario) ? $usuario : '').'|'.$request->ip())
                ->response(fn (Request $request, array $cabeceras) => response()->json([
                    'message' => 'Demasiados intentos. Espere un minuto e intente de nuevo.',
                    'retry_after' => (int) ($cabeceras['Retry-After'] ?? 60),
                ], 429, $cabeceras));
        });
    }
}
