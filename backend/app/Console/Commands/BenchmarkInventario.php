<?php

namespace App\Console\Commands;

use App\Enums\MetodoCaptura;
use App\Enums\Rol;
use App\Models\Categoria;
use App\Models\Configuracion;
use App\Models\Proveedor;
use App\Models\Referencia;
use App\Models\Unidad;
use App\Models\User;
use App\Services\IngresoService;
use App\Services\VentaService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Supuesto S-04: mide p50/p95 del escaneo (objetivo 300 ms), de la búsqueda y del informe Bajo
 * mínimo (RF-22) y de la cartera de clientes (RF-15, objetivo 500 ms)
 * pasando por todo el stack HTTP de Laravel (rutas, middleware, validación, BD), sin la red.
 * Crea sus datos dentro de una transacción que se revierte al final: no deja rastro.
 */
class BenchmarkInventario extends Command
{
    protected $signature = 'inventario:benchmark {--n=200 : Escaneos y búsquedas a medir} {--referencias=2000 : Referencias de relleno} {--clientes=500 : Clientes con cartera} {--ventas-por-cliente=10 : Ventas a crédito cerradas por cliente}';

    protected $description = 'Mide tiempos de escaneo y búsqueda contra los objetivos provisionales';

    public function handle(Kernel $kernel, IngresoService $ingresos, VentaService $ventas): int
    {
        $n = (int) $this->option('n');
        DB::beginTransaction();

        try {
            $usuario = User::create(['name' => 'bench', 'usuario' => 'bench-'.uniqid(), 'password' => 'x', 'rol' => Rol::Admin, 'activo' => true]);
            Auth::login($usuario);
            // S-43: solo el mostrador vende; los escaneos se miden con un mostrador y el resto con el admin.
            $vendedor = User::create(['name' => 'bench-mostrador', 'usuario' => 'bench-m-'.uniqid(), 'password' => 'x', 'rol' => Rol::Mostrador, 'activo' => true]);
            $proveedor = Proveedor::create(['nombre' => 'Proveedor benchmark']);
            $categoria = Categoria::firstOrCreate(['nombre' => 'Categoría benchmark']);

            $this->info("Sembrando {$this->option('referencias')} referencias y {$n} unidades…");
            $filas = [];
            for ($i = 1; $i <= (int) $this->option('referencias'); $i++) {
                $filas[] = ['codigo_referencia' => sprintf('BX%06d', $i), 'proveedor_id' => $proveedor->id,
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
            $venta = $ventas->abrir($vendedor);

            $escaneos = [];
            foreach ($codigos as $codigo) {
                $escaneos[] = $this->medir($kernel, $vendedor, Request::create("/api/ventas/{$venta->id}/escanear", 'POST',
                    ['codigo_barras' => $codigo, 'metodo_captura' => MetodoCaptura::Escaneado->value]), 201);
            }

            // S-31: el mismo escaneo con el código del proveedor (el sistema elige la unidad). La mitad
            // de las unidades con etiqueta impresa, para que la elección recorra los dos casos.
            $porReferencia = Referencia::where('codigo_referencia', 'BX000002')->first();
            $ingresoRef = $ingresos->crearBorrador(['proveedor_id' => $proveedor->id, 'numero_documento' => 'BENCH-REF', 'fecha' => now()->toDateString()], $usuario);
            $ingresos->agregarItem($ingresoRef, ['referencia_id' => $porReferencia->id, 'cantidad' => $n, 'costo_unitario' => 500]);
            $ingresos->confirmar($ingresoRef, $usuario);
            Unidad::where('referencia_id', $porReferencia->id)->whereRaw('id % 2 = 0')->update(['etiqueta_impresa_at' => now()]);

            $escaneosRef = [];
            for ($i = 0; $i < $n; $i++) {
                $escaneosRef[] = $this->medir($kernel, $vendedor, Request::create("/api/ventas/{$venta->id}/escanear", 'POST',
                    ['codigo_barras' => 'BX000002', 'metodo_captura' => MetodoCaptura::Escaneado->value]), 201);
            }

            // S-40: una lectura con cantidad 50 por código del proveedor (50 ítems en una transacción).
            $porLote = Referencia::where('codigo_referencia', 'BX000003')->first();
            $ingresoLote = $ingresos->crearBorrador(['proveedor_id' => $proveedor->id, 'numero_documento' => 'BENCH-50', 'fecha' => now()->toDateString()], $usuario);
            $ingresos->agregarItem($ingresoLote, ['referencia_id' => $porLote->id, 'cantidad' => 500, 'costo_unitario' => 500]);
            $ingresos->confirmar($ingresoLote, $usuario);
            $ventaLote = $ventas->abrir($vendedor);
            $escaneos50 = [];
            for ($i = 0; $i < 10; $i++) {
                $escaneos50[] = $this->medir($kernel, $vendedor, Request::create("/api/ventas/{$ventaLote->id}/escanear", 'POST',
                    ['codigo_barras' => 'BX000003', 'metodo_captura' => MetodoCaptura::Escaneado->value, 'cantidad' => 50]), 201);
            }

            $busquedas = [];
            for ($i = 0; $i < $n; $i++) {
                $q = $i % 2 ? sprintf('BX%04d', random_int(1, 1999)) : $codigos[$i % $codigos->count()];
                $busquedas[] = $this->medir($kernel, $usuario, Request::create('/api/buscar', 'GET', ['q' => $q]), 200);
            }

            // S-39: búsqueda de la referencia para una entrada (código exacto o texto, máx. 10).
            $referenciasAjuste = [];
            for ($i = 0; $i < $n; $i++) {
                $params = $i % 2 ? ['q' => sprintf('BX%04d', random_int(1, 1999))] : ['codigo' => sprintf('BX%06d', random_int(1, 1999))];
                $referenciasAjuste[] = $this->medir($kernel, $usuario, Request::create('/api/ajustes/referencia', 'GET', $params), 200);
            }

            // RF-22: mínimo general 3 para todo el catálogo de relleno (existencia 0: todas alertan)
            // y mínimos propios en una de cada diez, para que la consulta haga el trabajo completo.
            Configuracion::where('clave', Configuracion::STOCK_MINIMO_GENERAL)->update(['valor' => '3']);
            Referencia::where('proveedor_id', $proveedor->id)->whereRaw('id % 10 = 0')->update(['stock_minimo' => 5]);
            Referencia::where('proveedor_id', $proveedor->id)->whereRaw('id % 10 = 1')->update(['stock_minimo' => 0]);

            $bajoMinimo = [];
            for ($i = 0; $i < $n; $i++) {
                $filtro = match ($i % 3) {
                    0 => [], 1 => ['proveedor_id' => $proveedor->id], default => ['categoria_id' => $categoria->id]
                };
                $bajoMinimo[] = $this->medir($kernel, $usuario, Request::create('/api/reportes/bajo-minimo', 'GET', $filtro), 200);
            }

            // RF-15: clientes con ventas a crédito cerradas (2 ítems cada una), vencimientos repartidos
            // en los cinco rangos y abonos en la mitad de los clientes.
            $this->sembrarCartera($usuario, $codigos, (int) $this->option('clientes'), (int) $this->option('ventas-por-cliente'));
            $cartera = [];
            for ($i = 0; $i < $n; $i++) {
                $filtro = match ($i % 4) {
                    0 => ['solo_con_saldo' => 1],
                    1 => ['solo_vencidos' => 1],
                    2 => ['busqueda' => 'Cliente bench 1'],
                    default => ['solo_con_saldo' => 1, 'page' => 3],
                };
                $cartera[] = $this->medir($kernel, $usuario, Request::create('/api/cartera', 'GET', $filtro), 200);
            }

            $this->table(['endpoint', 'n', 'p50 ms', 'p95 ms', 'máx ms', 'objetivo p95'], [
                ['POST /ventas/{id}/escanear (etiqueta)', count($escaneos), $this->pct($escaneos, 50), $this->pct($escaneos, 95), max($escaneos), 300],
                ['POST /ventas/{id}/escanear (código proveedor, S-31)', count($escaneosRef), $this->pct($escaneosRef, 50), $this->pct($escaneosRef, 95), max($escaneosRef), 300],
                ['POST /ventas/{id}/escanear (cantidad 50, S-40)', count($escaneos50), $this->pct($escaneos50, 50), $this->pct($escaneos50, 95), max($escaneos50), 1000],
                ['GET /buscar', count($busquedas), $this->pct($busquedas, 50), $this->pct($busquedas, 95), max($busquedas), 500],
                ['GET /ajustes/referencia (S-39)', count($referenciasAjuste), $this->pct($referenciasAjuste, 50), $this->pct($referenciasAjuste, 95), max($referenciasAjuste), 500],
                ['GET /reportes/bajo-minimo', count($bajoMinimo), $this->pct($bajoMinimo, 50), $this->pct($bajoMinimo, 95), max($bajoMinimo), 500],
                ['GET /cartera', count($cartera), $this->pct($cartera, 50), $this->pct($cartera, 95), max($cartera), 500],
            ]);
            $this->line('Sin latencia de red: en red local sumar el RTT (normalmente < 5 ms).');
        } finally {
            DB::rollBack();
        }

        return self::SUCCESS;
    }

    /**
     * RF-15: siembra la cartera en bloque (sin pasar por los servicios, que harían miles de
     * consultas): los ítems reutilizan las unidades del benchmark porque solo importa el precio.
     *
     * @param  Collection<int, string>  $codigos
     */
    private function sembrarCartera(User $usuario, $codigos, int $clientes, int $ventasPorCliente): void
    {
        $this->info("Sembrando {$clientes} clientes con {$ventasPorCliente} ventas a crédito cada uno…");
        $ahora = now();
        $unidadIds = Unidad::whereIn('codigo_barras', $codigos)->pluck('id')->all();

        $filas = [];
        for ($c = 1; $c <= $clientes; $c++) {
            $filas[] = ['nombre' => "Cliente bench {$c}", 'documento' => 'BENCH'.str_pad((string) $c, 6, '0', STR_PAD_LEFT),
                'plazo_dias' => 30, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora];
        }
        foreach (array_chunk($filas, 500) as $lote) {
            DB::table('clientes')->insert($lote);
        }
        $clienteIds = DB::table('clientes')->where('documento', 'like', 'BENCH%')->pluck('id')->all();

        $ventas = [];
        foreach ($clienteIds as $i => $clienteId) {
            for ($v = 0; $v < $ventasPorCliente; $v++) {
                $cierre = $ahora->copy()->subDays(($i * 7 + $v * 13) % 180);
                $ventas[] = ['usuario_id' => $usuario->id, 'estado' => 'cerrada', 'forma_pago' => 'credito', 'cliente_id' => $clienteId,
                    'cerrada_at' => $cierre, 'vence_el' => $cierre->copy()->addDays(30)->toDateString(), 'created_at' => $cierre, 'updated_at' => $cierre];
            }
        }
        foreach (array_chunk($ventas, 1000) as $lote) {
            DB::table('ventas')->insert($lote);
        }

        $items = [];
        $ventaIds = DB::table('ventas')->whereIn('cliente_id', $clienteIds)->pluck('id');
        foreach ($ventaIds as $k => $ventaId) {
            foreach ([0, 1] as $j) {
                $items[] = ['venta_id' => $ventaId, 'unidad_id' => $unidadIds[($k * 2 + $j) % count($unidadIds)], 'precio_unitario' => 50000 + ($k % 7) * 10000,
                    'iva_porcentaje' => 19, 'metodo_captura' => 'escaneado', 'usuario_id' => $usuario->id, 'created_at' => $ahora];
            }
        }
        foreach (array_chunk($items, 1000) as $lote) {
            DB::table('venta_items')->insert($lote);
        }

        // Abonos generales a la mitad de los clientes y (S-32) uno dirigido a la venta más reciente
        // de uno de cada tres, para que la asignación haga el trabajo completo.
        $ultimaVenta = DB::table('ventas')->whereIn('cliente_id', $clienteIds)->groupBy('cliente_id')
            ->selectRaw('cliente_id, MAX(id) AS id')->pluck('id', 'cliente_id');
        $abonos = [];
        foreach ($clienteIds as $i => $clienteId) {
            if ($i % 2 === 0) {
                $abonos[] = ['cliente_id' => $clienteId, 'venta_id' => null, 'fecha' => $ahora->toDateString(), 'valor' => 150000, 'medio' => 'efectivo',
                    'usuario_id' => $usuario->id, 'created_at' => $ahora];
            }
            if ($i % 3 === 0) {
                $abonos[] = ['cliente_id' => $clienteId, 'venta_id' => $ultimaVenta[$clienteId] ?? null, 'fecha' => $ahora->toDateString(), 'valor' => 50000, 'medio' => 'transferencia',
                    'usuario_id' => $usuario->id, 'created_at' => $ahora];
            }
        }
        foreach (array_chunk($abonos, 1000) as $lote) {
            DB::table('abonos_clientes')->insert($lote);
        }
    }

    private function medir(Kernel $kernel, User $usuario, Request $request, int $esperado): float
    {
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(fn () => $usuario);
        // S-43: el guard toma el usuario de la sesión; se mide como $usuario y luego se restaura.
        $anterior = Auth::user();
        Auth::setUser($usuario);

        $inicio = hrtime(true);
        $respuesta = $kernel->handle($request);
        $ms = (hrtime(true) - $inicio) / 1e6;
        Auth::setUser($anterior);

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
