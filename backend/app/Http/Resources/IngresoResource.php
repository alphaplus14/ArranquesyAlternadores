<?php

namespace App\Http\Resources;

use App\Models\Ingreso;
use App\Models\IngresoItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Ingreso  RF-05 / RF-09 / RF-10.
 * Todas las cifras salen de Ingreso::totales() (TotalesIngreso): el frontend no recalcula.
 */
class IngresoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $totales = $this->relationLoaded('items') ? $this->totales() : null;

        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'estado' => $this->estado,
            'proveedor_id' => $this->proveedor_id,
            'proveedor' => $this->whenLoaded('proveedor', fn () => ['id' => $this->proveedor->id, 'nombre' => $this->proveedor->nombre]),
            'numero_documento' => $this->numero_documento,
            'fecha' => $this->fecha?->toDateString(),
            'descuento_total' => $this->descuento_total,
            'usuario' => $this->whenLoaded('usuario', fn () => ['id' => $this->usuario->id, 'name' => $this->usuario->name]),
            'confirmado_at' => $this->confirmado_at,
            'confirmado_por' => $this->whenLoaded('confirmadoPor', fn () => $this->confirmadoPor
                ? ['id' => $this->confirmadoPor->id, 'name' => $this->confirmadoPor->name]
                : null),
            'items' => $this->whenLoaded('items', fn () => $this->items
                ->map(fn (IngresoItem $i) => (new IngresoItemResource($i))->conTotales($totales?->lineas[$i->id] ?? null))
                ->all()),
            // RF-09 (ajuste): el descuento del documento se prorratea antes del IVA (S-21).
            'totales' => $totales ? [
                'subtotal' => $totales->subtotal,
                'descuento_lineas' => $totales->descuentoLineas,
                'descuento_total' => $totales->descuentoDocumento,
                'base' => $totales->base,
                'iva' => $totales->iva,
                'lineas_sin_iva' => $totales->lineasSinIva, // RF-10
                'total' => $totales->total,
            ] : null,
        ];
    }
}
