<?php

namespace App\Http\Resources;

use App\Models\Referencia;
use Illuminate\Http\Request;

/**
 * RF-08 / S-47: una fila de Agotados. La referencia de siempre más `universal_existencia`: la
 * existencia de las demás referencias activas de su código universal (null sin universal). Si es
 * mayor que 0, el repuesto no está agotado en conjunto (regla 2) y la pantalla lo marca.
 *
 * @mixin Referencia
 */
class AgotadoResource extends ReferenciaResource
{
    public function toArray(Request $request): array
    {
        $suma = $this->resource->getAttribute('universal_existencia');

        return [
            ...parent::toArray($request),
            'universal_existencia' => $suma === null ? null : (int) $suma,
        ];
    }
}
