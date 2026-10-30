<?php

use App\Enums\Rol;
use App\Models\MovimientoInventario;
use App\Models\Unidad;
use App\Models\Venta;
use App\Models\VentaItem;
use Symfony\Component\Process\Process;

/*
 * RF-06: dos mostradores escanean la misma unidad al mismo tiempo, desde dos procesos PHP con
 * conexiones MySQL independientes. lockForUpdate() hace que el segundo espere al primero y
 * luego encuentre la unidad vendida: solo una venta gana.
 */
it('RF-06: dos ventas concurrentes de la misma unidad: solo una gana', function () {
    sembrarDemo();
    $unidad = unidadDe('ARR-12V-9D');
    $ventas = collect([1, 2])->map(fn () => Venta::create([
        'usuario_id' => \App\Models\User::factory()->rol(Rol::Mostrador)->create()->id,
    ]));

    $inicio = microtime(true) + 1.5; // ambos procesos arrancan en el mismo instante
    $procesos = $ventas->map(function (Venta $venta) use ($unidad, $inicio) {
        $proceso = new Process(
            [PHP_BINARY, __DIR__.'/escanear.php', $venta->id, $unidad->codigo_barras, $venta->usuario_id, $inicio],
            base_path(),
            ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => config('database.connections.mysql.database')],
        );
        $proceso->setTimeout(30)->start();

        return $proceso;
    });

    $salidas = $procesos->map(function (Process $p) {
        $p->wait();

        return trim($p->getOutput().$p->getErrorOutput());
    });

    expect($salidas->filter(fn ($s) => $s === 'OK'))->toHaveCount(1)
        ->and($salidas->filter(fn ($s) => str_contains($s, 'no está disponible')))->toHaveCount(1)
        ->and(VentaItem::where('unidad_id', $unidad->id)->count())->toBe(1)
        ->and(MovimientoInventario::where('tipo', 'venta')->where('unidad_id', $unidad->id)->count())->toBe(1)
        ->and(Unidad::find($unidad->id)->estado->value)->toBe('vendida')
        ->and(referencia('ARR-12V-9D')->existencia)->toBe(0);
});
