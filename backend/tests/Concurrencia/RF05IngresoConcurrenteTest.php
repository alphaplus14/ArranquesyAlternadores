<?php

use App\Enums\Rol;
use App\Enums\TipoIngreso;
use App\Models\Ingreso;
use App\Models\IngresoItem;
use App\Models\Proveedor;
use App\Models\Unidad;
use App\Models\User;
use Symfony\Component\Process\Process;

/*
 * RF-05 / RF-04: un usuario confirma un ingreso mientras otro le agrega un ítem, desde dos
 * procesos PHP con conexiones MySQL independientes. El bloqueo de la fila del ingreso ordena
 * las dos operaciones: o el ítem entra antes y recibe sus unidades, o llega tarde y se rechaza.
 * Nunca queda un ítem de un ingreso confirmado sin unidades.
 */
it('RF-05: agregar un ítem mientras se confirma el ingreso no deja ítems sin unidades', function () {
    sembrarDemo();
    $usuario = User::factory()->rol(Rol::Logistica)->create();
    $sensor = referencia('SEN-TEMP-01');

    foreach (range(1, 3) as $ronda) {
        $ingreso = Ingreso::create([
            'tipo' => TipoIngreso::Remision,
            'proveedor_id' => Proveedor::firstOrFail()->id,
            'numero_documento' => "REM-CONC-{$ronda}",
            'fecha' => '2026-11-20',
            'usuario_id' => $usuario->id,
        ]);
        $ingreso->items()->create(['referencia_id' => $sensor->id, 'cantidad' => 1, 'costo_unitario' => 1000]);

        $inicio = microtime(true) + 1.5; // ambos procesos arrancan en el mismo instante
        $procesos = collect(['confirmar', 'agregar'])->map(function (string $accion) use ($ingreso, $usuario, $sensor, $inicio) {
            $proceso = new Process(
                [PHP_BINARY, __DIR__.'/ingreso.php', $accion, $ingreso->id, $usuario->id, $sensor->id, $inicio],
                base_path(),
                ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => config('database.connections.mysql.database')],
            );
            $proceso->setTimeout(30)->start();

            return $proceso;
        });

        [$confirmar, $agregar] = $procesos->map(function (Process $p) {
            $p->wait();

            return trim($p->getOutput().$p->getErrorOutput());
        })->all();

        $items = IngresoItem::where('ingreso_id', $ingreso->id)->get();
        $unidades = Unidad::whereIn('ingreso_item_id', $items->pluck('id'))->count();

        expect($confirmar)->toBe('OK')
            ->and(Ingreso::find($ingreso->id)->esBorrador())->toBeFalse()
            // Cada unidad facturada en un ítem del ingreso confirmado tiene su unidad física.
            ->and($unidades)->toBe((int) $items->sum('cantidad'))
            ->and($agregar === 'OK' || str_contains($agregar, 'ya está confirmado'))->toBeTrue("Salida inesperada: {$agregar}");
    }
});
