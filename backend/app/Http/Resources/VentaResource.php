<?php

namespace App\Http\Resources;

use App\Models\Venta;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Venta  RF-06 / RF-07: la venta se muestra completa, con ítems reversados incluidos. */
class VentaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'estado' => $this->estado,
            'forma_pago' => $this->forma_pago,
            'cliente' => $this->whenLoaded('cliente', fn () => $this->cliente ? [
                'id' => $this->cliente->id,
                'nombre' => $this->cliente->nombre,
                'documento' => $this->cliente->documento,
                'plazo_dias' => $this->cliente->plazo_dias,
            ] : null),
            'factura_contapyme' => $this->factura_contapyme,
            'usuario' => $this->whenLoaded('usuario', fn () => ['id' => $this->usuario->id, 'name' => $this->usuario->name]),
            'created_at' => $this->created_at,
            'cerrada_at' => $this->cerrada_at,
            'vence_el' => $this->vence_el?->toDateString(),
            'items' => VentaItemResource::collection($this->whenLoaded('items')),
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
