<?php

namespace App\Http\Resources;

use App\Models\MovimientoEmpleado;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MovimientoEmpleado  RF-27: un préstamo o abono de empleado; anulado sigue visible. Sin saldo (S-57). */
class MovimientoEmpleadoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'empleado_id' => $this->empleado_id,
            'empleado' => $this->whenLoaded('empleado', fn () => ['id' => $this->empleado->id, 'nombre' => $this->empleado->nombre]),
            'tipo' => $this->tipo,
            'fecha' => $this->fecha->toDateString(),
            'valor' => $this->valor,
            'forma' => $this->forma,
            'cuenta' => $this->whenLoaded('cuenta', fn () => $this->cuenta ? ['id' => $this->cuenta->id, 'nombre' => $this->cuenta->nombre] : null),
            'apertura' => $this->apertura,
            'nota' => $this->nota,
            'usuario' => $this->whenLoaded('usuario', fn () => ['id' => $this->usuario->id, 'name' => $this->usuario->name]),
            'created_at' => $this->created_at,
            'anulacion' => $this->whenLoaded('anulacion', fn () => $this->anulacion ? [
                'motivo' => $this->anulacion->motivo,
                'created_at' => $this->anulacion->created_at,
                'usuario' => $this->anulacion->relationLoaded('usuario') && $this->anulacion->usuario
                    ? ['id' => $this->anulacion->usuario->id, 'name' => $this->anulacion->usuario->name] : null,
            ] : null),
        ];
    }
}
