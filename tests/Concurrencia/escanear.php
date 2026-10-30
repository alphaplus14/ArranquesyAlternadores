<?php

/*
 * Proceso auxiliar de RF06VentaConcurrenteTest: espera hasta el instante indicado y escanea.
 * Uso: php escanear.php {venta_id} {codigo_barras} {usuario_id} {inicio_unix_float}
 */

use App\Enums\MetodoCaptura;
use App\Models\User;
use App\Models\Venta;
use App\Services\VentaService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $ventaId, $codigo, $usuarioId, $inicio] = $argv;

$venta = Venta::findOrFail($ventaId);
$usuario = User::findOrFail($usuarioId);
\Illuminate\Support\Facades\DB::connection()->getPdo(); // conectar antes de la barrera

$espera = (float) $inicio - microtime(true);
if ($espera > 0) {
    usleep((int) ($espera * 1e6));
}

try {
    $app->make(VentaService::class)->escanear($venta, $codigo, MetodoCaptura::Escaneado, $usuario);
    echo 'OK';
} catch (Throwable $e) {
    echo 'ERROR: '.$e->getMessage();
}
