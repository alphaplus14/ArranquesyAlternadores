<?php

namespace App\Services;

use App\Enums\EstadoVenta;
use App\Enums\MetodoCaptura;
use App\Exceptions\ReglaInventarioException;
use App\Models\Unidad;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaItem;
use Illuminate\Support\Facades\DB;

/**
 * RF-06: el descargue es la venta de mostrador. Cada escaneo descuenta la unidad de inmediato,
 * no al cerrar, y deja el usuario responsable. El sistema no factura (eso es Contapyme).
 * RF-12: cada ítem registra si el código fue escaneado o digitado.
 */
class VentaService
{
    public function __construct(private readonly InventarioService $inventario) {}

    public function abrir(User $usuario): Venta
    {
        return Venta::create(['usuario_id' => $usuario->id])->refresh();
    }

    public function escanear(Venta $venta, string $codigoBarras, MetodoCaptura $metodo, User $usuario): VentaItem
    {
        return DB::transaction(function () use ($venta, $codigoBarras, $metodo, $usuario) {
            $venta = Venta::query()->lockForUpdate()->findOrFail($venta->id);

            if (! $venta->estaAbierta()) {
                throw new ReglaInventarioException('La venta está cerrada.');
            }

            // lockForUpdate: si dos mostradores escanean la misma unidad, el segundo espera
            // al primero y luego la encuentra vendida.
            $unidad = Unidad::query()
                ->where('codigo_barras', trim($codigoBarras))
                ->lockForUpdate()
                ->first();

            if (! $unidad) {
                throw new ReglaInventarioException("No existe una unidad con el código {$codigoBarras}.", 404);
            }

            $item = VentaItem::create([
                'venta_id' => $venta->id,
                'unidad_id' => $unidad->id,
                'metodo_captura' => $metodo,
                'usuario_id' => $usuario->id,
            ]);

            $this->inventario->darSalida($unidad, $venta, $usuario->id, $metodo);

            return $item->setRelation('unidad', $unidad);
        });
    }

    public function cerrar(Venta $venta): Venta
    {
        return DB::transaction(function () use ($venta) {
            $venta = Venta::query()->lockForUpdate()->findOrFail($venta->id);

            if (! $venta->estaAbierta()) {
                throw new ReglaInventarioException('La venta ya está cerrada.');
            }

            $venta->estado = EstadoVenta::Cerrada;
            $venta->cerrada_at = now();
            $venta->save();

            return $venta;
        });
    }
}
