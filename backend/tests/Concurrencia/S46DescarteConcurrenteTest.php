<?php

use App\Enums\Rol;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaItem;
use Symfony\Component\Process\Process;

/*
 * S-46 (9): un escaneo y la limpieza de ventas vacías al mismo tiempo, desde procesos PHP con
 * conexiones MySQL independientes. Ambos bloquean primero la venta: o gana el escaneo (la venta
 * queda abierta con su ítem) o gana el descarte (el escaneo se rechaza). Nunca una descartada con
 * ítems vigentes.
 */
it('S-46: escaneo y descarte concurrentes: nunca queda una venta descartada con ítems vigentes', function () {
    sembrarDemo();
    $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => config('database.connections.mysql.database')];
    $usuario = User::factory()->rol(Rol::Mostrador)->create();

    foreach (['RN-4410', 'ARR-12V-9D'] as $codigoRef) {
        $venta = Venta::create(['usuario_id' => $usuario->id]);
        $unidad = unidadDe($codigoRef);
        $inicio = microtime(true) + 1.5;

        $procesos = collect([
            [PHP_BINARY, __DIR__.'/escanear.php', $venta->id, $unidad->codigo_barras, $usuario->id, $inicio],
            [PHP_BINARY, __DIR__.'/descartar.php', $inicio],
        ])->map(function ($cmd) use ($env) {
            $proceso = new Process($cmd, base_path(), $env);
            $proceso->setTimeout(30)->start();

            return $proceso;
        });

        $salidas = $procesos->map(function (Process $p) {
            $p->wait();

            return trim($p->getOutput().$p->getErrorOutput());
        });

        $venta->refresh();
        $items = VentaItem::where('venta_id', $venta->id)->count();
        if ($venta->estaDescartada()) {
            expect($items)->toBe(0)
                ->and($salidas[0])->toContain('descartada')
                ->and($unidad->refresh()->estado->value)->toBe('disponible');
        } else {
            expect($venta->estaAbierta())->toBeTrue()
                ->and($items)->toBe(1)
                ->and($salidas[0])->toBe('OK');
        }
    }

    expect(Venta::where('estado', 'descartada')->whereHas('items', fn ($q) => $q->whereDoesntHave('reversoItem'))->count())->toBe(0);
});
