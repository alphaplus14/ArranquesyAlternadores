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
            // S-34: solo en la verificación de un ajuste: 'etiqueta' o 'referencia' (unidad asignada).
            'origen_codigo' => $this->when($this->resource->getAttribute('origen_codigo') !== null, fn () => $this->resource->getAttribute('origen_codigo')),
            'referencia' => new ReferenciaResource($this->whenLoaded('referencia')),
        ];
    }
}
