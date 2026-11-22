<?php

namespace App\Http\Resources;

use App\Models\IngresoItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IngresoItem  RF-05 / RF-09 / RF-10 / RF-20: una línea del ingreso.
 * neto, base e IVA dependen del descuento del documento (prorrateo de RF-09): solo vienen
 * cuando la línea se muestra dentro de su ingreso (IngresoResource los pasa con conTotales()).
 */
class IngresoItemResource extends JsonResource
{
    /** @var array{neto:string, base:string, iva:string}|null */
    private ?array $totales = null;

    /** @param array{neto:string, base:string, iva:string}|null $totales */
    public function conTotales(?array $totales): self
    {
        $this->totales = $totales;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'referencia_id' => $this->referencia_id,
            'referencia' => $this->relationLoaded('referencia') ? [
                'codigo_referencia' => $this->referencia->codigo_referencia,
                'descripcion' => $this->referencia->descripcion,
            ] : null,
            'cantidad' => $this->cantidad,
            'cantidad_cubierta' => (int) $this->cantidad_cubierta, // RF-19
            'costo_unitario' => $this->costo_unitario,
            'descuento_porcentaje' => $this->descuento_porcentaje, // S-22
            'iva_porcentaje' => $this->iva_porcentaje, // 0 = sin IVA
            'neto' => $this->totales['neto'] ?? null,
            'base' => $this->totales['base'] ?? null,
            'iva' => $this->totales['iva'] ?? null,
        ];
    }
}
