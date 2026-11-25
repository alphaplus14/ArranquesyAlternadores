<?php

use App\Enums\Rol;
use App\Models\MovimientoInventario;
use App\Models\Referencia;
use App\Models\Unidad;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaItem;
use Symfony\Component\Process\Process;

/*
 * S-31 (h): dos mostradores escanean el MISMO código de proveedor al mismo tiempo, desde dos
 * procesos PHP con conexiones MySQL independientes. FOR UPDATE SKIP LOCKED hace que cada uno tome
 * una unidad distinta sin esperar al otro; con una sola unidad, el segundo recibe "sin unidades".
 */

/** Lanza los dos escaneos en el mismo instante y devuelve [salidas, segundos de cada proceso]. */
function escanearALaVez(string $codigo): array
{
    $ventas = collect([1, 2])->map(fn () => Venta::create([
        'usuario_id' => User::factory()->rol(Rol::Mostrador)->create()->id,
    ]));

    $inicio = microtime(true) + 1.5;
    $procesos = $ventas->map(function (Venta $venta) use ($codigo, $inicio) {
        $proceso = new Process(
            [PHP_BINARY, __DIR__.'/escanear.php', $venta->id, $codigo, $venta->usuario_id, $inicio],
            base_path(),
            ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => config('database.connections.mysql.database')],
        );
        $proceso->setTimeout(30)->start();

        return $proceso;
    });

    return $procesos->map(function (Process $p) {
        $p->wait();

        return trim($p->getOutput().$p->getErrorOutput());
    })->all();
}

it('S-31 (h): con 2 unidades, dos escaneos simultáneos del código del proveedor venden unidades distintas', function () {
    sembrarDemo();
    $ref = Referencia::factory()->create(['codigo_referencia' => 'CONC-2']);
    ingresar($ref, 2);

    $salidas = escanearALaVez('CONC-2');

    $vendidas = VentaItem::whereIn('unidad_id', Unidad::where('referencia_id', $ref->id)->pluck('id'))->pluck('unidad_id');
    expect($salidas)->toBe(['OK', 'OK'])
        ->and($vendidas)->toHaveCount(2)
        ->and($vendidas->unique())->toHaveCount(2)
        ->and(MovimientoInventario::where('tipo', 'venta')->where('referencia_id', $ref->id)->count())->toBe(2)
        ->and($ref->refresh()->existencia)->toBe(0);
});

it('S-31 (h): con 1 unidad, el segundo escaneo simultáneo recibe "sin unidades disponibles" y no hay doble venta', function () {
    sembrarDemo();
    $ref = Referencia::factory()->create(['codigo_referencia' => 'CONC-1']);
    ingresar($ref, 1);

    $salidas = collect(escanearALaVez('CONC-1'));

    expect($salidas->filter(fn ($s) => $s === 'OK'))->toHaveCount(1)
        ->and($salidas->filter(fn ($s) => str_contains($s, 'no tiene unidades disponibles')))->toHaveCount(1)
        ->and(VentaItem::whereIn('unidad_id', Unidad::where('referencia_id', $ref->id)->pluck('id'))->count())->toBe(1)
        ->and($ref->refresh()->existencia)->toBe(0);
});
