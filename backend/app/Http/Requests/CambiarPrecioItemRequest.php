<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** RF-14 / S-18: precio libre de un ítem en una venta a crédito abierta. Ningún precio puede ser 0. */
class CambiarPrecioItemRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'precio_unitario' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
        ];
    }
}
