<?php

namespace App\Services;

use App\Enums\TipoReverso;
use App\Exceptions\ReglaInventarioException;
use App\Models\Reverso;
use App\Models\ReversoItem;
use App\Models\Unidad;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaItem;
use Illuminate\Support\Facades\DB;

/**
 * RF-07: una devolución o anulación es un movimiento nuevo ligado a la venta, con usuario, fecha
 * y motivo. La venta y sus ítems nunca se borran. Quitar un ítem de una venta abierta también
 * es un reverso (anulación de ese ítem), no un DELETE.
 *
 * Sin venta_item_id se reversan todos los ítems de la venta que no estén ya reversados.
 */
class ReversoService
{
    public function __construct(private readonly InventarioService $inventario) {}

    public function reversar(Venta $venta, TipoReverso $tipo, string $motivo, ?int $ventaItemId, User $usuario): Reverso
    {
        return DB::transaction(function () use ($venta, $tipo, $motivo, $ventaItemId, $usuario) {
            $venta = Venta::query()->lockForUpdate()->findOrFail($venta->id);

            // Una devolución supone que el cliente ya se llevó la mercancía.
            if ($tipo === TipoReverso::Devolucion && $venta->estaAbierta()) {
                throw new ReglaInventarioException('Una venta abierta no admite devolución: anule el ítem.', 422);
            }

            $items = VentaItem::query()
                ->where('venta_id', $venta->id)
                ->when($ventaItemId, fn ($q) => $q->whereKey($ventaItemId))
                ->whereDoesntHave('reversoItem')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                throw new ReglaInventarioException(
                    $ventaItemId ? 'El ítem no pertenece a la venta o ya fue reversado.' : 'La venta no tiene ítems por reversar.',
                    422,
                );
            }

            $reverso = Reverso::create([
                'venta_id' => $venta->id,
                'tipo' => $tipo,
                'motivo' => $motivo,
                'usuario_id' => $usuario->id,
            ]);

            // UNIQUE(venta_item_id) en reverso_items es la última barrera contra un doble reverso.
            ReversoItem::insert($items->map(fn (VentaItem $i) => [
                'reverso_id' => $reverso->id,
                'venta_item_id' => $i->id,
            ])->all());

            $unidades = Unidad::query()
                ->whereIn('id', $items->pluck('unidad_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($unidades as $unidad) {
                $this->inventario->devolver($unidad, $reverso, $usuario->id);
            }

            return $reverso->load('items');
        });
    }
}
