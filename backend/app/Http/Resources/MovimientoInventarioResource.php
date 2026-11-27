<?php

namespace App\Http\Resources;

use App\Models\MovimientoInventario;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * RF-21 (S-87): una fila del historial de movimientos de inventario. La etiqueta del origen la
 * deja resuelta el servicio, en bloque.
 *
 * @mixin MovimientoInventario
 */
class MovimientoInventarioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ref = $this->referencia;

        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'created_at' => $this->created_at,
            'metodo_captura' => $this->metodo_captura,
            'usuario' => $this->usuario ? ['id' => $this->usuario->id, 'name' => $this->usuario->name] : null,
            'referencia' => [
                'id' => $ref->id,
                'codigo_referencia' => $ref->codigo_referencia,
                'descripcion' => $ref->descripcion,
                'calidad' => $ref->calidad,
                'proveedor' => ['id' => $ref->proveedor->id, 'nombre' => $ref->proveedor->nombre],
            ],
            'unidad' => $this->unidad ? ['id' => $this->unidad->id, 'codigo_barras' => $this->unidad->codigo_barras] : null,
            'origen' => $this->origen_type === null ? null : [
                'tipo' => $this->origen_type,
                'id' => $this->origen_id,
                'etiqueta' => $this->getAttribute('etiqueta_origen'),
            ],
        ];
    }
}
