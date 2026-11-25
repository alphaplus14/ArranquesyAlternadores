<?php

use App\Enums\Rol;
use App\Models\MovimientoInventario;
use App\Models\Unidad;
use App\Models\User;
use Symfony\Component\Process\Process;

/*
 * S-39 (d): dos entradas por ajuste a la misma referencia al mismo tiempo, desde dos procesos PHP con
 * conexiones MySQL independientes. La secuencia de códigos y el bloqueo de la referencia hacen que
 * no se repitan códigos ni se pierda existencia.
 */
it('S-39 (d): dos entradas simultáneas no repiten códigos de barras ni pierden existencia', function () {
    sembrarDemo();
    $ref = refS38('CONC-ALTA', 2);
    $usuario = User::factory()->rol(Rol::Logistica)->create();
    $codigosAntes = Unidad::count();

    $inicio = microtime(true) + 1.5;
    $procesos = collect([30, 25])->map(function (int $cantidad) use ($ref, $usuario, $inicio) {
        $p = new Process(
            [PHP_BINARY, __DIR__.'/alta.php', $ref->id, $cantidad, $usuario->id, $inicio],
            base_path(),
            ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => config('database.connections.mysql.database')],
        );
        $p->setTimeout(60)->start();

        return $p;
    });

    $salidas = $procesos->map(function (Process $p) {
        $p->wait();

        return trim($p->getOutput().$p->getErrorOutput());
    })->all();

    expect($salidas)->toBe(['OK', 'OK'])
        ->and($ref->refresh()->existencia)->toBe(2 + 30 + 25)
        ->and(Unidad::where('referencia_id', $ref->id)->whereNotNull('ajuste_id')->count())->toBe(55)
        ->and(Unidad::count())->toBe($codigosAntes + 55)
        ->and(Unidad::query()->distinct()->count('codigo_barras'))->toBe(Unidad::count())
        ->and(MovimientoInventario::where('tipo', 'alta')->where('referencia_id', $ref->id)->count())->toBe(55);
});
