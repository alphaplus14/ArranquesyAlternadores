<?php

/*
 * Proceso auxiliar de S39EntradaConcurrenteTest: espera hasta el instante indicado y registra una
 * entrada por ajuste (alta) de N unidades de la referencia.
 * Uso: php alta.php {referencia_id} {cantidad} {usuario_id} {inicio_unix_float}
 */

use App\Enums\MetodoCaptura;
use App\Enums\MotivoAjuste;
use App\Models\User;
use App\Services\AjusteService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $referenciaId, $cantidad, $usuarioId, $inicio] = $argv;

$usuario = User::findOrFail($usuarioId);
DB::connection()->getPdo(); // conectar antes de la barrera

$espera = (float) $inicio - microtime(true);
if ($espera > 0) {
    usleep((int) ($espera * 1e6));
}

try {
    $app->make(AjusteService::class)->alta(MotivoAjuste::SobranteInventario, null, (int) $referenciaId, (int) $cantidad, MetodoCaptura::Escaneado, $usuario);
    echo 'OK';
} catch (Throwable $e) {
    echo 'ERROR: '.$e->getMessage();
}
