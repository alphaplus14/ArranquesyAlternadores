<?php

namespace App\Console\Commands;

use App\Enums\MetodoCaptura;
use App\Enums\Rol;
use App\Models\Ingreso;
use App\Models\Marca;
use App\Models\Proveedor;
use App\Models\Categoria;
use App\Models\Referencia;
use App\Models\Unidad;
use App\Models\User;
use App\Models\Venta;
use App\Services\IngresoService;
use App\Services\VentaService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Supuesto S-04: mide p50/p95 del escaneo (objetivo 300 ms) y de la búsqueda (objetivo 500 ms)
 * pasando por todo el stack HTTP de Laravel (rutas, middleware, validación, BD), sin la red.
 * Crea sus datos dentro de una transacción que se revierte al final: no deja rastro.
 */
class BenchmarkInventario extends Command
{
    protected $signature = 'inventario:benchmark {--n=200 : Escaneos y búsquedas a medir} {--referencias=2000 : Referencias de relleno}';

    protected $description = 'Mide tiempos de escaneo y búsqueda contra los objetivos provisionales';

    public function handle(Kernel $kernel, IngresoService $ingresos, VentaService $ventas): int
    {
        $n = (int) $this->option('n');
        DB::beginTransaction();

        try {
            $usuario = User::create(['name' => 'bench', 'email' => 'bench-'.uniqid().'@local', 'password' => 'x', 'rol' => Rol::Admin, 'activo' => true]);
            Auth::login($usuario);
            $proveedor = Proveedor::create(['nombre' => 'Proveedor benchmark']);
            $marca = Marca::firstOrCreate(['nombre' => 'Marca benchmark']);
            $categoria = Categoria::firstOrCreate(['nombre' => 'Categoría benchmark']);

            $this->info("Sembrando {$this->option('referencias')} referencias y {$n} unidades…");
            $filas = [];
            for ($i = 1; $i <= (int) $this->option('referencias'); $i++) {
                $filas[] = ['codigo_referencia' => sprintf('BX%06d', $i), 'marca_id' => $marca->id, 'proveedor_id' => $proveedor->id,
                    'categoria_id' => $categoria->id, 'descripcion' => "Pieza de prueba {$i}", 'precio_venta' => 1000,
                    'existencia' => 0, 'created_at' => now(), 'updated_at' => now()];
            }
            foreach (array_chunk($filas, 500) as $lote) {
                Referencia::insert($lote);
            }

            $referencia = Referencia::where('codigo_referencia', 'BX000001')->first();
            $ingreso = $ingresos->crearBorrador(['proveedor_id' => $proveedor->id, 'numero_documento' => 'BENCH', 'fecha' => now()->toDateString()], $usuario);
            $ingresos->agregarItem($ingreso, ['referencia_id' => $referencia->id, 'cantidad' => $n, 'costo_unitario' => 500]);
            $ingresos->confirmar($ingreso, $usuario);

            $codigos = Unidad::where('referencia_id', $referencia->id)->pluck('codigo_barras');
            $venta = $ventas->abrir($usuario);

            $escaneos = [];
            foreach ($codigos as $codigo) {
                $escaneos[] = $this->medir($kernel, $usuario, Request::create("/api/ventas/{$venta->id}/escanear", 'POST',
                    ['codigo_barras' => $codigo, 'metodo_captura' => MetodoCaptura::Escaneado->value]), 201);
            }

            $busquedas = [];
            for ($i = 0; $i < $n; $i++) {
                $q = $i % 2 ? sprintf('BX%04d', random_int(1, 1999)) : $codigos[$i % $codigos->count()];
                $busquedas[] = $this->medir($kernel, $usuario, Request::create('/api/buscar', 'GET', ['q' => $q]), 200);
            }

            $this->table(['endpoint', 'n', 'p50 ms', 'p95 ms', 'máx ms', 'objetivo p95'], [
                ['POST /ventas/{id}/escanear', count($escaneos), $this->pct($escaneos, 50), $this->pct($escaneos, 95), max($escaneos), 300],
                ['GET /buscar', count($busquedas), $this->pct($busquedas, 50), $this->pct($busquedas, 95), max($busquedas), 500],
            ]);
            $this->line('Sin latencia de red: en red local sumar el RTT (normalmente < 5 ms).');
        } finally {
            DB::rollBack();
        }

        return self::SUCCESS;
    }

    private function medir(Kernel $kernel, User $usuario, Request $request, int $esperado): float
    {
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(fn () => $usuario);

        $inicio = hrtime(true);
        $respuesta = $kernel->handle($request);
        $ms = (hrtime(true) - $inicio) / 1e6;

        if ($respuesta->getStatusCode() !== $esperado) {
            throw new \RuntimeException("{$request->getPathInfo()} respondió {$respuesta->getStatusCode()}: {$respuesta->getContent()}");
        }

        return round($ms, 2);
    }

    /** @param list<float> $valores */
    private function pct(array $valores, int $p): float
    {
        sort($valores);

        return $valores[max(0, (int) ceil($p / 100 * count($valores)) - 1)];
    }
}
