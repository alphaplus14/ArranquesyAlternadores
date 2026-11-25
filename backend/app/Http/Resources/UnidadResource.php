<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Unidad */
class UnidadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'codigo_barras' => $this->codigo_barras,
            'estado' => $this->estado,
            'referencia_id' => $this->referencia_id,
            // RF-28: en las unidades de un ingreso, para preseleccionar las pendientes de etiqueta.
            // Solo cuando quien llama la marca (IngresoController::unidades); el resto no cambia de forma.
            'etiqueta_impresa' => $this->when($this->resource->getAttribute('etiqueta_impresa') !== null, fn () => (bool) $this->resource->getAttribute('etiqueta_impresa')),
            // S-34: solo en la verificación de un ajuste: 'etiqueta' o 'referencia' (unidad asignada).
            'origen_codigo' => $this->when($this->resource->getAttribute('origen_codigo') !== null, fn () => $this->resource->getAttribute('origen_codigo')),
            'referencia' => new ReferenciaResource($this->whenLoaded('referencia')),
        ];
    }
}
