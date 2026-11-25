<?php

namespace Database\Seeders;

use App\Enums\EstadoUnidad;
use App\Enums\FormaPago;
use App\Enums\MedioPago;
use App\Enums\MetodoCaptura;
use App\Enums\Rol;
use App\Enums\TipoReverso;
use App\Models\Cliente;
use App\Models\Referencia;
use App\Models\Unidad;
use App\Models\User;
use App\Models\Venta;
use App\Services\AbonoService;
use App\Services\CarteraService;
use App\Services\ReversoService;
use App\Services\VentaService;
use App\Support\RangoFechas;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SOLO DESARROLLO. Carga pesada para mirar el panel de gestión (RF-23): cientos de ventas
 * cerradas en los últimos 90 días, crédito con abonos, devoluciones y unidades envejecidas
 * para antigüedad y stock estancado. Entra por VentaService, ReversoService y AbonoService.
 *
 * php artisan db:seed --class=CargaPanelSeeder
 */
class CargaPanelSeeder extends Seeder
{
    public function run(VentaService $ventas, ReversoService $reversos, AbonoService $abonos): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('CargaPanelSeeder es solo para desarrollo.');
        }

        $admin = User::where('rol', Rol::Admin)->firstOrFail();
        Auth::setUser($admin);

        $vendedores = User::query()->where('rol', Rol::Mostrador)->where('activo', true)->get();
        $clientes = Cliente::query()->where('activo', true)->get();
        if ($vendedores->isEmpty() || $clientes->isEmpty()) {
            throw new RuntimeException('Hacen falta mostradores y clientes activos.');
        }

        $hoy = now()->copy()->startOfDay();
        $hechas = 0;
        $unidades = 0;
        $abonados = 0;

        $ya = DB::table('configuraciones')->where('clave', 'carga_panel')->exists();
        if (! $ya && Venta::count() >= 400) {
            $ya = true;
            DB::table('configuraciones')->insert([
                'clave' => 'carga_panel',
                'valor' => 'ventas',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $refs = $ya ? collect() : Referencia::query()->activas()
            ->where('precio_venta', '>', 0)
            ->whereBetween('existencia', [4, 60])
            ->orderBy('id')
            ->limit(280)
            ->get(['id', 'codigo_referencia', 'existencia', 'precio_venta']);

        $this->command?->info($ya ? 'Las ventas de la carga ya están. Siguen abonos y antigüedad.' : 'Referencias a vender: '.$refs->count());

        try {
            foreach ($refs as $i => $ref) {
                $agotar = $i % 7 === 0;
                $cantidad = min(VentaService::MAX_CANTIDAD, (int) $ref->existencia, $agotar ? (int) $ref->existencia : max(3, (int) floor($ref->existencia * 0.75)));
                $dia = $hoy->copy()->subDays($i % 90)->setTime(8 + ($i % 9), ($i * 7) % 60);
                Carbon::setTestNow($dia);

                $credito = $i % 3 === 0;
                $vendedor = $vendedores[$i % $vendedores->count()];
                $cliente = $credito ? $clientes[$i % $clientes->count()] : null;

                $venta = $ventas->abrir($vendedor, $credito ? FormaPago::Credito : FormaPago::Contado, $cliente?->id);
                $items = $ventas->escanearVarias(
                    $venta,
                    $ref->codigo_referencia,
                    $i % 5 === 0 ? MetodoCaptura::Digitado : MetodoCaptura::Escaneado,
                    $vendedor,
                    $ref->id,
                    $cantidad,
                );

                if ($credito && $i % 6 === 0) {
                    $item = $items->first();
                    $ventas->cambiarPrecio($venta, $item, round((float) $item->precio_unitario * 0.9, -2), $vendedor);
                }

                $cerrada = $ventas->cerrar($venta);
                $hechas++;
                $unidades += $items->count();

                if ($i % 11 === 0) {
                    Carbon::setTestNow($dia->copy()->addDays(2)->min($hoy->copy()->subHour()));
                    $reversos->reversar(
                        $cerrada,
                        TipoReverso::Devolucion,
                        'Carga de prueba del panel: el cliente devolvió la pieza',
                        $items->first()->id,
                        $vendedor,
                    );
                }

                if ($hechas % 40 === 0) {
                    $this->command?->info("Ventas {$hechas}, unidades {$unidades}");
                }
            }

            if (! $ya) {
                DB::table('configuraciones')->insert([
                    'clave' => 'carga_panel',
                    'valor' => (string) $hechas,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            Carbon::setTestNow();
            $abonados = $this->abonar($abonos, app(CarteraService::class), $admin);
            $this->envejecerDisponibles();
        } finally {
            Carbon::setTestNow();
        }

        Cache::flush();
        $this->command?->info("CargaPanelSeeder: {$hechas} ventas, {$unidades} unidades, {$abonados} abonos.");
    }

    /** Abona el 40 % de una de cada dos ventas a crédito cerradas en la ventana del panel. */
    private function abonar(AbonoService $abonos, CarteraService $cartera, User $admin): int
    {
        $zona = config('app.zona_local', 'America/Bogota');
        $hoy = RangoFechas::hoy();
        $ventas = Venta::query()
            ->where('forma_pago', FormaPago::Credito)
            ->where('estado', 'cerrada')
            ->where('cerrada_at', '>=', now()->subDays(100))
            ->whereNotNull('cliente_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('abonos_clientes')->whereColumn('abonos_clientes.venta_id', 'ventas.id')->where('abonos_clientes.referencia', 'like', 'CARGA-%'))
            ->orderBy('id')
            ->get(['id', 'cliente_id', 'cerrada_at']);

        $hechos = 0;
        foreach ($ventas as $n => $venta) {
            if ($n % 2 !== 0) {
                continue;
            }
            $fecha = $venta->cerrada_at->copy()->timezone($zona)->toDateString();
            if ($fecha > $hoy) {
                continue;
            }
            $cliente = Cliente::query()->whereKey($venta->cliente_id)->where('activo', true)->first();
            if (! $cliente) {
                continue;
            }
            $pendiente = $cartera->pendienteDeVenta($cliente, $venta->id);
            if ($pendiente === null || bccomp($pendiente, '1000', 2) < 0) {
                continue;
            }
            $valor = bcmul($pendiente, '0.40', 0);
            if (bccomp($valor, '1', 2) < 0) {
                continue;
            }
            $abonos->registrar($cliente, $fecha, $valor, $n % 4 === 0 ? MedioPago::Transferencia : MedioPago::Efectivo, 'CARGA-'.$venta->id, 'Abono de la carga de prueba del panel', $admin, $venta->id);
            $hechos++;
        }

        return $hechos;
    }

    /** Reparte la antigüedad de lo que sigue disponible para las franjas del panel. */
    private function envejecerDisponibles(): void
    {
        $dias = [15, 55, 130, 240, 420];
        foreach ($dias as $resto => $atras) {
            Unidad::query()
                ->where('estado', EstadoUnidad::Disponible)
                ->whereRaw('id % 5 = ?', [$resto])
                ->update(['created_at' => now()->subDays($atras)]);
        }

        DB::table('unidades')->where('estado', EstadoUnidad::Disponible->value)->update(['updated_at' => now()]);
    }
}
