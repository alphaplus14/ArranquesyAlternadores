<?php

namespace App\Console\Commands;

use App\Services\VentaService;
use Illuminate\Console\Command;

/**
 * S-46: pasa a `descartada` las ventas abiertas sin ítems vigentes (no las borra; RF-07). Por
 * defecto, solo las de días anteriores (día de Bogotá); --todas incluye las de hoy; --dry-run solo
 * cuenta. Corre a diario en el scheduler (routes/console.php). Si el scheduler no corre en el equipo
 * del local, la limpieza igual ocurre al abrir cada venta (VentaService::abrirYEscanear).
 */
class DescartarVentasVacias extends Command
{
    protected $signature = 'ventas:descartar-vacias
        {--todas : Incluye las ventas vacías de hoy}
        {--dry-run : Solo cuenta, no descarta}';

    protected $description = 'Descarta (sin borrar) las ventas abiertas sin ítems vigentes (S-46)';

    public function handle(VentaService $ventas): int
    {
        $incluirHoy = (bool) $this->option('todas');
        $alcance = $incluirHoy ? 'incluidas las de hoy' : 'de días anteriores';

        if ($this->option('dry-run')) {
            $n = $ventas->consultaVacias(null, $incluirHoy)->count();
            $this->info("Se descartarían {$n} venta(s) abierta(s) sin ítems vigentes ({$alcance}). No se cambió nada.");

            return self::SUCCESS;
        }

        $n = $ventas->descartarVacias(null, $incluirHoy);
        $this->info("Se descartaron {$n} venta(s) abierta(s) sin ítems vigentes ({$alcance}).");

        return self::SUCCESS;
    }
}
