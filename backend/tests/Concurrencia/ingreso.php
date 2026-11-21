<?php

/*
 * Proceso auxiliar de RF05IngresoConcurrenteTest: espera hasta el instante indicado y, sobre el
 * mismo ingreso, confirma o agrega un ítem.
 * Uso: php ingreso.php {confirmar|agregar} {ingreso_id} {usuario_id} {referencia_id} {inicio_unix_float}
 */

use App\Models\Ingreso;
use App\Models\User;
use App\Services\IngresoService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $accion, $ingresoId, $usuarioId, $referenciaId, $inicio] = $argv;

$ingreso = Ingreso::findOrFail($ingresoId);
$usuario = User::findOrFail($usuarioId);
DB::connection()->getPdo(); // conectar antes de la barrera

$espera = (float) $inicio - microtime(true);
if ($espera > 0) {
    usleep((int) ($espera * 1e6));
}

try {
    $servicio = $app->make(IngresoService::class);
    if ($accion === 'confirmar') {
        $servicio->confirmar($ingreso, $usuario);
    } else {
        $servicio->agregarItem($ingreso, ['referencia_id' => (int) $referenciaId, 'cantidad' => 2, 'costo_unitario' => 1000]);
    }
    echo 'OK';
} catch (Throwable $e) {
    echo 'ERROR: '.$e->getMessage();
}
