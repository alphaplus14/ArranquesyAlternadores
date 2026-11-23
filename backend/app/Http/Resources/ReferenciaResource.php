<?php

namespace App\Http\Resources;

use App\Enums\Rol;
use App\Models\Referencia;
use App\Services\StockMinimoService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Referencia */
class ReferenciaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // RF-22 / S-28: el stock mínimo es de reposición; a mostrador no se le envía.
        $verMinimo = $request->user()?->rol !== Rol::Mostrador;
        // null si la referencia es de un universal y no llegó su suma (búsqueda, agotados).
        $minimo = $verMinimo ? app(StockMinimoService::class)->evaluarReferencia($this->resource) : null;

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
            // RF-22: stock_minimo propio (null = general; 0 = no maneja). Con universal, manda el del universal.
            $this->mergeWhen($verMinimo, fn () => [
                'stock_minimo' => $this->stock_minimo,
                'minimo_efectivo' => $minimo['minimo_efectivo'] ?? null,
                'minimo_origen' => $minimo['origen'] ?? null,
                'minimo_por_universal' => $this->codigo_universal_id !== null,
                'bajo_minimo' => $minimo['bajo_minimo'] ?? false,
            ]),
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
                    + ($verMinimo ? ['stock_minimo' => $this->codigoUniversal->stock_minimo] : [])
                : null),
            'unidades_disponibles' => UnidadResource::collection($this->whenLoaded('unidades')),
            'updated_at' => $this->updated_at,
        ];
    }
}
