<?php

namespace App\Http\Resources;

use App\Models\AbonoCliente;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * RF-15: un abono, con su anulación si la tiene (sigue visible, no cuenta en el saldo).
 *
 * @mixin AbonoCliente
 */
class AbonoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cliente_id' => $this->cliente_id,
            // S-32: null = general (FIFO); con número, dirigido a esa venta a crédito.
            'venta_id' => $this->venta_id,
            'aplicado_a' => $this->aplicadoA(),
            'fecha' => $this->fecha?->toDateString(),
            'valor' => $this->valor,
            'medio' => $this->medio,
            // RF-24 (S-48): cuenta (null en registros anteriores).
            'cuenta' => $this->whenLoaded('cuenta', fn () => $this->cuenta ? ['id' => $this->cuenta->id, 'nombre' => $this->cuenta->nombre, 'tipo' => $this->cuenta->tipo] : null),
            'referencia' => $this->referencia,
            'nota' => $this->nota,
            'created_at' => $this->created_at,
            'usuario' => $this->whenLoaded('usuario', fn () => ['id' => $this->usuario->id, 'name' => $this->usuario->name]),
            'anulado' => $this->relationLoaded('anulacion') ? $this->anulacion !== null : null,
            'anulacion' => $this->whenLoaded('anulacion', fn () => $this->anulacion ? [
                'id' => $this->anulacion->id,
                'motivo' => $this->anulacion->motivo,
                'created_at' => $this->anulacion->created_at,
                'usuario' => $this->anulacion->relationLoaded('usuario')
                    ? ['id' => $this->anulacion->usuario->id, 'name' => $this->anulacion->usuario->name]
                    : null,
            ] : null),
        ];
    }
}
