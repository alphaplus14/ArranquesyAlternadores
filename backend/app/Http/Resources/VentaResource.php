<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Venta  RF-06 / RF-07: la venta se muestra completa, con ítems reversados incluidos. */
class VentaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'estado' => $this->estado,
            'usuario' => $this->whenLoaded('usuario', fn () => ['id' => $this->usuario->id, 'name' => $this->usuario->name]),
            'created_at' => $this->created_at,
            'cerrada_at' => $this->cerrada_at,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'metodo_captura' => $item->metodo_captura,
                'created_at' => $item->created_at,
                'usuario' => ['id' => $item->usuario->id, 'name' => $item->usuario->name],
                'unidad' => new UnidadResource($item->unidad),
                'reverso_id' => $item->reversoItem?->reverso_id,
            ])),
            'reversos' => $this->whenLoaded('reversos', fn () => $this->reversos->map(fn ($r) => [
                'id' => $r->id,
                'tipo' => $r->tipo,
                'motivo' => $r->motivo,
                'created_at' => $r->created_at,
                'usuario' => ['id' => $r->usuario->id, 'name' => $r->usuario->name],
                'venta_item_ids' => $r->items->pluck('venta_item_id'),
            ])),
        ];
    }
}
