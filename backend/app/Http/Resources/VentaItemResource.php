<?php

namespace App\Http\Resources;

use App\Models\VentaItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VentaItem  RF-06 / RF-07: un ítem de la venta tal como lo pinta la tabla del mostrador.
 * Lo usan GET /ventas/{id} (dentro de VentaResource) y POST /ventas/{id}/escanear, para que el
 * frontend agregue el ítem escaneado a la venta en caché sin volver a pedirla.
 * Espera cargados usuario, reversoItem y unidad.referencia.proveedor.
 */
class VentaItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'precio_unitario' => $this->precio_unitario,
            'iva_porcentaje' => $this->iva_porcentaje,
            'metodo_captura' => $this->metodo_captura,
            'origen_codigo' => $this->origen_codigo, // S-31: etiqueta | referencia
            'created_at' => $this->created_at,
            'usuario' => ['id' => $this->usuario->id, 'name' => $this->usuario->name],
            'unidad' => new UnidadResource($this->unidad),
            'reverso_id' => $this->reversoItem?->reverso_id,
        ];
    }
}
