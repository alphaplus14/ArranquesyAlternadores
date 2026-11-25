<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// S-46: limpieza diaria de ventas abiertas sin ítems vigentes (de días anteriores), sin borrarlas.
Schedule::command('ventas:descartar-vacias')->dailyAt('23:55')->timezone(config('app.zona_local', 'America/Bogota'));
