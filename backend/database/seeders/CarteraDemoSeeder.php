<?php

namespace Database\Seeders;

use App\Enums\FormaPago;
use App\Enums\MetodoCaptura;
use App\Enums\Rol;
use App\Models\AbonoCliente;
use App\Models\Cliente;
use App\Models\CuentaPago;
use App\Models\Unidad;
use App\Models\User;
use App\Services\AbonoService;
use App\Services\VentaService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * RF-15 (S-30): datos de ejemplo para ver la Cartera en pantalla. Se corre a mano, después del
 * demo: `php artisan db:seed --class=CarteraDemoSeeder`. No está en DatabaseSeeder porque vende
 * unidades del demo y las pruebas cuentan con esas existencias.
 *
 * Deja tres clientes: uno con una venta vencida hace 40 días y otra al día, con un abono dirigido
 * que salda la vencida (S-79); otro con saldo a favor de un abono antiguo sin venta (registro anterior
 * a S-79, que se sigue leyendo); otro sin abonos.
 */
class CarteraDemoSeeder extends Seeder
{
    public function run(VentaService $ventas, AbonoService $abonos): void
    {
        $mostrador = User::where('rol', Rol::Mostrador)->firstOrFail();

        $taller = Cliente::firstOrCreate(['documento' => '900555111'], ['nombre' => 'Taller El Pistón', 'telefono' => '3001234567', 'plazo_dias' => 30]);
        $motos = Cliente::firstOrCreate(['documento' => '1020304050'], ['nombre' => 'Motos y Repuestos Juan', 'plazo_dias' => 15]);
        $flota = Cliente::firstOrCreate(['documento' => '800777222'], ['nombre' => 'Transportes La Flota', 'plazo_dias' => 45]);

        $vieja = $this->venta($ventas, $mostrador, $taller, [60000]);
        $this->venta($ventas, $mostrador, $taller, [50000]);
        $this->fechar($vieja, now()->subDays(70), now()->subDays(40)->toDateString());
        $abonos->registrar($taller, now('America/Bogota')->toDateString(), 60000, CuentaPago::query()->where('tipo', 'banco')->first() ?? CuentaPago::efectivo(), 'TR-1001', null, $mostrador, $vieja);

        // Abono antiguo sin venta (anterior a S-79): hoy ya no se registra así; se escribe directo
        // para mostrar cómo se leen los registros viejos con saldo a favor.
        $this->venta($ventas, $mostrador, $motos, [45000]);
        $efectivo = CuentaPago::efectivo();
        AbonoCliente::create([
            'cliente_id' => $motos->id, 'venta_id' => null, 'fecha' => now('America/Bogota')->toDateString(), 'valor' => 50000,
            'medio' => $efectivo->tipo->medio(), 'cuenta_pago_id' => $efectivo->id, 'referencia' => null,
            'nota' => 'Pagó de más; queda a favor', 'usuario_id' => $mostrador->id,
        ]);

        $vencida = $this->venta($ventas, $mostrador, $flota, [120000, 80000]);
        $this->fechar($vencida, now()->subDays(100), now()->subDays(55)->toDateString());
    }

    /** @param list<int> $precios */
    private function venta(VentaService $ventas, User $usuario, Cliente $cliente, array $precios): int
    {
        $venta = $ventas->abrir($usuario, FormaPago::Credito, $cliente->id);
        $codigos = Unidad::where('estado', 'disponible')->orderBy('id')->limit(count($precios))->pluck('codigo_barras');

        foreach ($precios as $i => $precio) {
            $item = $ventas->escanear($venta, $codigos[$i], MetodoCaptura::Digitado, $usuario);
            $ventas->cambiarPrecio($venta, $item, $precio, $usuario);
        }

        return $ventas->cerrar($venta)->id;
    }

    /** Solo para el ejemplo: mueve el cierre y el vencimiento hacia atrás. */
    private function fechar(int $ventaId, $cierre, string $vence): void
    {
        DB::table('ventas')->where('id', $ventaId)->update(['cerrada_at' => $cierre, 'vence_el' => $vence]);
    }
}
