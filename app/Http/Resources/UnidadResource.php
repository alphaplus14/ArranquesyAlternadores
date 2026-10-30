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
            'referencia' => new ReferenciaResource($this->whenLoaded('referencia')),
        ];
    }
}
