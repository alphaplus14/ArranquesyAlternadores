<?php

/*
 * Proceso auxiliar de S46DescarteConcurrenteTest: espera hasta el instante indicado y descarta las
 * ventas vacías (incluidas las de hoy). Uso: php descartar.php {inicio_unix_float}
 */

use App\Services\VentaService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $inicio] = $argv;
DB::connection()->getPdo(); // conectar antes de la barrera

$espera = (float) $inicio - microtime(true);
if ($espera > 0) {
    usleep((int) ($espera * 1e6));
}

try {
    echo 'DESCARTADAS '.$app->make(VentaService::class)->descartarVacias(null, true);
} catch (Throwable $e) {
    echo 'ERROR: '.$e->getMessage();
}
