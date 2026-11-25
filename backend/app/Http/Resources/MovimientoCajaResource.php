<?php

namespace App\Http\Resources;

use App\Models\MovimientoCaja;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MovimientoCaja  RF-26: un movimiento de caja; anulado sigue visible con su motivo. */
class MovimientoCajaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fecha' => $this->fecha->toDateString(),
            'grupo' => $this->grupo,
            'grupo_nombre' => $this->grupo->nombre(),
            'categoria' => $this->whenLoaded('categoria', fn () => ['id' => $this->categoria->id, 'nombre' => $this->categoria->nombre]),
            'concepto' => $this->concepto,
            'valor' => $this->valor,
            'cuenta' => $this->whenLoaded('cuenta', fn () => ['id' => $this->cuenta->id, 'nombre' => $this->cuenta->nombre, 'tipo' => $this->cuenta->tipo]),
            'referencia' => $this->referencia,
            'usuario' => $this->whenLoaded('usuario', fn () => ['id' => $this->usuario->id, 'name' => $this->usuario->name]),
            'created_at' => $this->created_at,
            'anulacion' => $this->whenLoaded('anulacion', fn () => $this->anulacion ? [
                'motivo' => $this->anulacion->motivo,
                'created_at' => $this->anulacion->created_at,
                'usuario' => $this->anulacion->relationLoaded('usuario') && $this->anulacion->usuario
                    ? ['id' => $this->anulacion->usuario->id, 'name' => $this->anulacion->usuario->name]
                    : null,
            ] : null),
        ];
    }
}
