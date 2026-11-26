<?php

namespace App\Services;

use App\Enums\EstadoVenta;
use App\Enums\MetodoCaptura;
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
 * S-38: con una LISTA de ítems se reversan esos (todos deben ser de la venta y estar sin reverso),
 * en un solo reverso: así una devolución de varias unidades es un solo movimiento.
 * RF-12: $metodo solo llega cuando la devolución se registra por el código de la unidad
 * (logística, DevolucionService); se guarda en el movimiento.
 */
class ReversoService
{
    public function __construct(
        private readonly InventarioService $inventario,
        private readonly CajaService $caja,
    ) {}

    public function reversar(
        Venta $venta,
        TipoReverso $tipo,
        string $motivo,
        int|array|null $ventaItemId,
        User $usuario,
        ?MetodoCaptura $metodo = null,
    ): Reverso {
        return DB::transaction(function () use ($venta, $tipo, $motivo, $ventaItemId, $usuario, $metodo) {
            $venta = Venta::query()->lockForUpdate()->findOrFail($venta->id);

            // S-46: una venta descartada no tiene ítems vigentes ni admite movimientos nuevos.
            if ($venta->estaDescartada()) {
                throw new ReglaInventarioException($venta->motivoNoAbierta(), 422);
            }

            // S-73: un registro de solo valor (sin ítems) solo se anula, y entero: un reverso con el monto
            // (= valor_total) y sin reverso_items. No mueve inventario ni caja (es crédito).
            if ($venta->esSoloValor()) {
                if ($tipo !== TipoReverso::Anulacion) {
                    throw new ReglaInventarioException('Una venta a crédito registrada por su valor no tiene productos que devolver: anúlela.', 422);
                }
                if ($ventaItemId !== null) {
                    throw new ReglaInventarioException('Una venta a crédito registrada por su valor se anula completa.', 422);
                }
                if (Reverso::where('venta_id', $venta->id)->exists()) {
                    throw new ReglaInventarioException("La venta #{$venta->id} ya fue anulada.", 422);
                }

                return Reverso::create([
                    'venta_id' => $venta->id,
                    'tipo' => TipoReverso::Anulacion,
                    'motivo' => $motivo,
                    'valor' => $venta->valor_total,
                    'usuario_id' => $usuario->id,
                ])->load('items');
            }

            // S-50 / S-53: revertir una venta de contado ya cerrada devuelve dinero de la caja de hoy.
            if ($venta->estado === EstadoVenta::Cerrada && ! $venta->esCredito()) {
                $this->caja->exigirDiaAbierto('registrar devoluciones de ventas de contado');
            }

            // Una devolución supone que el cliente ya se llevó la mercancía.
            if ($tipo === TipoReverso::Devolucion && $venta->estaAbierta()) {
                throw new ReglaInventarioException('Una venta abierta no admite devolución: anule el ítem.', 422);
            }

            $ids = $ventaItemId === null ? null : array_values(array_unique(array_map('intval', (array) $ventaItemId)));

            $items = VentaItem::query()
                ->where('venta_id', $venta->id)
                ->when($ids !== null, fn ($q) => $q->whereKey($ids))
                ->whereDoesntHave('reversoItem')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty() || ($ids !== null && $items->count() !== count($ids))) {
                throw new ReglaInventarioException(
                    match (true) {
                        $ids === null => 'La venta no tiene ítems por reversar.',
                        count($ids) === 1 => 'El ítem no pertenece a la venta o ya fue reversado.',
                        default => 'Algún ítem no pertenece a la venta o ya fue reversado: no se reversó ninguno.',
                    },
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
                $this->inventario->devolver($unidad, $reverso, $usuario->id, $metodo);
            }

            return $reverso->load('items');
        });
    }
}
