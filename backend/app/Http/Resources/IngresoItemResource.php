<?php

namespace App\Http\Resources;

use App\Models\IngresoItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin IngresoItem  RF-05 / RF-09 / RF-10 / RF-20: una línea del ingreso. */
class IngresoItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'referencia_id' => $this->referencia_id,
            'referencia' => $this->relationLoaded('referencia') ? [
                'codigo_referencia' => $this->referencia->codigo_referencia,
                'descripcion' => $this->referencia->descripcion,
            ] : null,
            'cantidad' => $this->cantidad,
            'costo_unitario' => $this->costo_unitario,
            'descuento' => $this->descuento,
            'iva_porcentaje' => $this->iva_porcentaje,
            'sin_iva' => $this->sin_iva,
            'subtotal' => $this->subtotal(),
            'iva' => $this->iva(),
        ];
    }
}
