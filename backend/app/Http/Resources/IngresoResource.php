<?php

namespace App\Http\Resources;

use App\Models\Ingreso;
use App\Models\IngresoItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Ingreso  RF-05 / RF-09 / RF-10 / RF-16.
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
            'forma_pago' => $this->forma_pago, // RF-16
            'proveedor_id' => $this->proveedor_id,
            'proveedor' => $this->whenLoaded('proveedor', fn () => [
                'id' => $this->proveedor->id,
                'nombre' => $this->proveedor->nombre,
                'plazo_dias' => $this->proveedor->plazo_dias,
                'asesor' => $this->proveedor->asesor, // S-29
            ]),
            'numero_documento' => $this->numero_documento,
            'fecha' => $this->fecha?->toDateString(),
            'vence_el' => $this->vence_el?->toDateString(), // RF-16: digitado; null en contado
            // S-22: en pesos; si se digitó en %, los pesos vigentes salen de las líneas (totales).
            'descuento_documento' => $totales?->descuentoDocumento ?? $this->descuento_documento,
            'descuento_documento_porcentaje' => $this->descuento_documento_porcentaje,
            // RF-16: fijado al confirmar; null en borrador (y en los confirmados antes de RF-16).
            'total' => $this->total,
            'usuario' => $this->whenLoaded('usuario', fn () => ['id' => $this->usuario->id, 'name' => $this->usuario->name]),
            'confirmado_at' => $this->confirmado_at,
            'confirmado_por' => $this->whenLoaded('confirmadoPor', fn () => $this->confirmadoPor
                ? ['id' => $this->confirmadoPor->id, 'name' => $this->confirmadoPor->name]
                : null),
            // RF-19: la factura que cubre esta remisión, o las remisiones que cubre esta factura.
            'factura' => $this->whenLoaded('factura', fn () => $this->factura
                ? ['id' => $this->factura->id, 'numero_documento' => $this->factura->numero_documento, 'estado' => $this->factura->estado]
                : null),
            'remisiones' => $this->whenLoaded('remisiones', fn () => $this->remisiones->map(fn ($r) => [
                'id' => $r->id,
                'numero_documento' => $r->numero_documento,
                'fecha' => $r->fecha->toDateString(),
                'total' => $r->total,
                'unidades' => (int) $r->unidades,
            ])->all()),
            // RF-28: historial de correcciones de este ingreso confirmado.
            'correcciones' => $this->whenLoaded('correcciones', fn () => IngresoCorreccionResource::collection($this->correcciones)->resolve()),
            'corregido' => $this->whenLoaded('correcciones', fn () => $this->correcciones->isNotEmpty()),
            'diferencia_aceptada' => (bool) $this->diferencia_aceptada,
            'diferencia_motivo' => $this->diferencia_motivo,
            'items' => $this->whenLoaded('items', fn () => $this->items
                ->map(fn (IngresoItem $i) => (new IngresoItemResource($i))->conTotales($totales?->lineas[$i->id] ?? null))
                ->all()),
            // RF-09 (ajuste) / S-21 / S-22: el descuento del documento se prorratea antes del IVA.
            'totales' => $totales ? [
                'subtotal_bruto' => $totales->subtotalBruto,
                'descuento_lineas' => $totales->descuentoLineas,
                'subtotal_neto' => $totales->subtotalNeto,
                'descuento_documento' => $totales->descuentoDocumento,
                'base_gravada' => $totales->baseGravada,
                'base_sin_iva' => $totales->baseSinIva,
                'iva' => $totales->iva,
                'lineas_sin_iva' => $totales->lineasSinIva, // RF-10
                'total' => $totales->total,
                // S-66: el borrador acepta el descuento; con true no se deja confirmar.
                'descuento_excede_subtotal' => $totales->descuentoExcedeSubtotal(),
            ] : null,
        ];
    }
}
