<?php

namespace App\Http\Resources;

use App\Models\VentaItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * RF-07 / S-27: el ítem vendido de una unidad, su venta y, si ya se registró, su devolución.
 *
 * @mixin VentaItem
 */
class DevolucionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $referencia = $this->unidad->referencia;
        $reverso = $this->reversoItem?->reverso;

        return [
            'venta_item_id' => $this->id,
            'precio_unitario' => $this->precio_unitario,
            'iva_porcentaje' => $this->iva_porcentaje,
            'unidad' => [
                'id' => $this->unidad->id,
                'codigo_barras' => $this->unidad->codigo_barras,
                'estado' => $this->unidad->estado,
            ],
            'referencia' => [
                'id' => $referencia->id,
                'codigo_referencia' => $referencia->codigo_referencia,
                'descripcion' => $referencia->descripcion,
                'calidad' => $referencia->calidad,
                'proveedor' => ['id' => $referencia->proveedor->id, 'nombre' => $referencia->proveedor->nombre],
            ],
            'venta' => [
                'id' => $this->venta->id,
                'forma_pago' => $this->venta->forma_pago,
                'created_at' => $this->venta->created_at,
                'cerrada_at' => $this->venta->cerrada_at,
                'cliente' => $this->venta->cliente
                    ? ['id' => $this->venta->cliente->id, 'nombre' => $this->venta->cliente->nombre, 'documento' => $this->venta->cliente->documento]
                    : null,
            ],
            'reverso' => $reverso ? [
                'id' => $reverso->id,
                'tipo' => $reverso->tipo,
                'motivo' => $reverso->motivo,
                'created_at' => $reverso->created_at,
            ] : null,
        ];
    }
}
