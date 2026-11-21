<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** RF-05 / RF-09 / RF-10 / S-22: una línea del ingreso. */
class IngresoItemRequest extends FormRequest
{
    public function rules(): array
    {
        $parcial = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'referencia_id' => [$parcial, 'integer', 'exists:referencias,id'],
            'cantidad' => [$parcial, 'integer', 'min:1', 'max:10000'],
            'costo_unitario' => [$parcial, 'numeric', 'min:0', 'max:9999999999'],
            // S-22: descuento de línea en %; IVA por línea (19, 5, 0…; 0 = sin IVA). Si no llega, se
            // copia el IVA de la referencia.
            'descuento_porcentaje' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'iva_porcentaje' => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
