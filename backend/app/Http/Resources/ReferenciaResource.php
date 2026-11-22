<?php

namespace App\Http\Resources;

use App\Models\Referencia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Referencia */
class ReferenciaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'codigo_referencia' => $this->codigo_referencia,
            'codigo_fabricante' => $this->codigo_fabricante, // S-25
            'calidad' => $this->calidad,
            'descripcion' => $this->descripcion,
            'precio_venta' => $this->precio_venta,
            'iva_venta_porcentaje' => $this->iva_venta_porcentaje,
            'existencia' => $this->existencia,
            'activo' => $this->activo, // S-26
            'proveedor_id' => $this->proveedor_id,
            'categoria_id' => $this->categoria_id,
            'codigo_universal_id' => $this->codigo_universal_id,
            'proveedor' => $this->whenLoaded('proveedor', fn () => ['id' => $this->proveedor->id, 'nombre' => $this->proveedor->nombre]),
            'categoria' => $this->whenLoaded('categoria', fn () => [
                'id' => $this->categoria->id,
                'nombre' => $this->categoria->nombre,
                'admite_codigo_universal' => $this->categoria->admite_codigo_universal,
            ]),
            'codigo_universal' => $this->whenLoaded('codigoUniversal', fn () => $this->codigoUniversal
                ? ['id' => $this->codigoUniversal->id, 'codigo' => $this->codigoUniversal->codigo]
                : null),
            'unidades_disponibles' => UnidadResource::collection($this->whenLoaded('unidades')),
            'updated_at' => $this->updated_at,
        ];
    }
}
