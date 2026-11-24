<?php

namespace App\Http\Resources;

use App\Models\PagoProveedor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PagoProveedor  RF-17: el pago se muestra siempre, también anulado. */
class PagoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'proveedor_id' => $this->proveedor_id,
            'fecha' => $this->fecha->toDateString(),
            'valor' => $this->valor,
            'medio' => $this->medio,
            'referencia' => $this->referencia,
            'nota' => $this->nota,
            'usuario' => $this->whenLoaded('usuario', fn () => ['id' => $this->usuario->id, 'name' => $this->usuario->name]),
            'created_at' => $this->created_at,
            'anulacion' => $this->whenLoaded('anulacion', fn () => $this->anulacion ? [
                'motivo' => $this->anulacion->motivo,
                'usuario' => ['id' => $this->anulacion->usuario->id, 'name' => $this->anulacion->usuario->name],
                'created_at' => $this->anulacion->created_at,
            ] : null),
        ];
    }
}
