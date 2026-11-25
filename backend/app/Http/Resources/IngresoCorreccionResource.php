<?php

namespace App\Http\Resources;

use App\Models\IngresoCorreccion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin IngresoCorreccion  RF-28: una corrección de ingreso, con el antes y el después. */
class IngresoCorreccionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'created_at' => $this->created_at,
            'usuario' => $this->whenLoaded('usuario', fn () => ['id' => $this->usuario->id, 'name' => $this->usuario->name]),
            'ingreso' => $this->whenLoaded('ingreso', fn () => [
                'id' => $this->ingreso->id,
                'tipo' => $this->ingreso->tipo,
                'numero_documento' => $this->ingreso->numero_documento,
                'proveedor' => $this->ingreso->relationLoaded('proveedor') && $this->ingreso->proveedor
                    ? ['id' => $this->ingreso->proveedor->id, 'nombre' => $this->ingreso->proveedor->nombre]
                    : null,
            ]),
            'motivo' => $this->motivo,
            'unidades_creadas' => $this->unidades_creadas,
            'unidades_retiradas' => $this->unidades_retiradas,
            'total_anterior' => $this->total_anterior,
            'total_nuevo' => $this->total_nuevo,
            'antes' => $this->antes,
            'despues' => $this->despues,
        ];
    }
}
