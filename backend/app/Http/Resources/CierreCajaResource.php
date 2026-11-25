<?php

namespace App\Http\Resources;

use App\Models\CierreCaja;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CierreCaja  RF-25: cierre de caja con los valores guardados y, si la tiene, su reapertura. */
class CierreCajaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fecha' => $this->fecha->toDateString(),
            // S-49: null en los cierres anteriores a la apertura diaria.
            'apertura' => $this->apertura,
            'efectivo_contado' => $this->efectivo_contado,
            'efectivo_esperado' => $this->efectivo_esperado,
            'diferencia' => $this->diferencia,
            'saldo_anterior' => $this->saldo_anterior,
            'saldo_acumulado' => $this->saldo_acumulado,
            'observacion' => $this->observacion,
            'cerrado_at' => $this->cerrado_at,
            'usuario' => $this->whenLoaded('usuario', fn () => $this->usuario ? ['id' => $this->usuario->id, 'name' => $this->usuario->name] : null),
            'reapertura' => $this->whenLoaded('reapertura', fn () => $this->reapertura ? [
                'motivo' => $this->reapertura->motivo,
                'created_at' => $this->reapertura->created_at,
                'usuario' => $this->reapertura->relationLoaded('usuario') && $this->reapertura->usuario
                    ? ['id' => $this->reapertura->usuario->id, 'name' => $this->reapertura->usuario->name]
                    : null,
            ] : null),
        ];
    }
}
