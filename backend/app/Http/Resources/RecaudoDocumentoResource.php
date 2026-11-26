<?php

namespace App\Http\Resources;

use App\Models\RecaudoDocumento;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RecaudoDocumento  S-76: recaudo de documento (caja del día y consulta del contable). */
class RecaudoDocumentoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fecha' => $this->fecha->toDateString(),
            'tipo_documento' => $this->tipo_documento,
            'numero_documento' => $this->numero_documento,
            // "Factura N.º 12345" / "Remisión N.º 45".
            'documento' => $this->tipo_documento->descripcion($this->numero_documento),
            'valor' => $this->valor,
            'cuenta' => $this->whenLoaded('cuenta', fn () => ['id' => $this->cuenta->id, 'nombre' => $this->cuenta->nombre, 'tipo' => $this->cuenta->tipo]),
            'observacion' => $this->observacion,
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
