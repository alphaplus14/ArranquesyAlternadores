<?php

namespace App\Providers;

use App\Models\Ingreso;
use App\Models\Reverso;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Resources\Json\JsonResource;
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
    }
}
