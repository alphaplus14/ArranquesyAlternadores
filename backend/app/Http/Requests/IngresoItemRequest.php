<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** RF-05 / RF-09 / RF-10: una línea del ingreso. */
class IngresoItemRequest extends FormRequest
{
    public function rules(): array
    {
        $parcial = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'referencia_id' => [$parcial, 'integer', 'exists:referencias,id'],
            'cantidad' => [$parcial, 'integer', 'min:1', 'max:10000'],
            'costo_unitario' => [$parcial, 'numeric', 'min:0', 'max:9999999999'],
            'descuento' => ['sometimes', 'numeric', 'min:0', 'max:9999999999'],
            'iva_porcentaje' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'sin_iva' => ['sometimes', 'boolean'],
        ];
    }
}
