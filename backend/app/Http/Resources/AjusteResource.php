<?php

namespace App\Http\Resources;

use App\Models\AjusteInventario;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * RF-21: un ajuste (baja o reintegro) con sus unidades, su referencia y su proveedor.
 *
 * @mixin AjusteInventario
 */
class AjusteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'motivo' => $this->motivo,
            'nota' => $this->nota,
            'created_at' => $this->created_at,
            'usuario' => $this->whenLoaded('usuario', fn () => ['id' => $this->usuario->id, 'name' => $this->usuario->name]),
            'cantidad' => $this->items_count ?? $this->whenLoaded('items', fn () => $this->items->count()),
            'unidades' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->unidad->id,
                'codigo_barras' => $item->unidad->codigo_barras,
                'estado' => $item->unidad->estado,
                'metodo_captura' => $item->metodo_captura,
                'reintegrable' => (bool) ($item->reintegrable ?? false),
                'referencia' => [
                    'id' => $item->unidad->referencia->id,
                    'codigo_referencia' => $item->unidad->referencia->codigo_referencia,
                    'descripcion' => $item->unidad->referencia->descripcion,
                    'calidad' => $item->unidad->referencia->calidad,
                    'proveedor' => ['id' => $item->unidad->referencia->proveedor->id, 'nombre' => $item->unidad->referencia->proveedor->nombre],
                ],
            ])->values()),
        ];
    }
}
