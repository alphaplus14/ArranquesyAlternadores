<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Ingreso  RF-05 / RF-09 / RF-10 */
class IngresoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $items = $this->whenLoaded('items');
        $cargados = $this->relationLoaded('items');

        $subtotal = $cargados ? round($this->items->sum(fn ($i) => $i->subtotal()), 2) : null;
        $iva = $cargados ? round($this->items->sum(fn ($i) => $i->iva()), 2) : null;
        $descuentoLineas = $cargados ? round($this->items->sum(fn ($i) => (float) $i->descuento), 2) : null;

        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'estado' => $this->estado,
            'proveedor_id' => $this->proveedor_id,
            'proveedor' => $this->whenLoaded('proveedor', fn () => ['id' => $this->proveedor->id, 'nombre' => $this->proveedor->nombre]),
            'numero_documento' => $this->numero_documento,
            'cufe' => $this->cufe,
            'fecha' => $this->fecha?->toDateString(),
            'usuario' => $this->whenLoaded('usuario', fn () => ['id' => $this->usuario->id, 'name' => $this->usuario->name]),
            'confirmado_at' => $this->confirmado_at,
            'items' => $this->whenLoaded('items', fn () => $items->map(fn ($i) => [
                'id' => $i->id,
                'referencia_id' => $i->referencia_id,
                'referencia' => $i->relationLoaded('referencia') ? [
                    'codigo_referencia' => $i->referencia->codigo_referencia,
                    'descripcion' => $i->referencia->descripcion,
                ] : null,
                'cantidad' => $i->cantidad,
                'costo_unitario' => $i->costo_unitario,
                'descuento' => $i->descuento,
                'iva_porcentaje' => $i->iva_porcentaje,
                'sin_iva' => $i->sin_iva,
                'subtotal' => $i->subtotal(),
                'iva' => $i->iva(),
            ])),
            // RF-09: el descuento de la factura se muestra explícito.
            'totales' => $cargados ? [
                'subtotal' => $subtotal,
                'descuento_lineas' => $descuentoLineas,
                'descuento_total' => $this->descuento_total,
                'iva' => $iva,
                'lineas_sin_iva' => $this->items->where('sin_iva', true)->count(), // RF-10
                'total' => round($subtotal - (float) $this->descuento_total + $iva, 2),
            ] : null,
        ];
    }
}
