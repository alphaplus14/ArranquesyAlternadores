<?php

namespace App\Http\Requests;

use App\Enums\TipoDocumentoVenta;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** S-76: registrar un recaudo de documento en la caja del día (solo mostrador; lo garantiza la ruta). */
class RecaudoDocumentoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'tipo_documento' => ['required', Rule::enum(TipoDocumentoVenta::class)],
            'numero_documento' => ['required', 'string', 'max:60'],
            'valor' => ['required', 'numeric', 'gt:0', 'max:999999999999', 'decimal:0,2'],
            'cuenta_pago_id' => ['required', 'integer'],
            'observacion' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'tipo_documento.required' => 'Elija el tipo de documento.',
            'tipo_documento.enum' => 'El tipo de documento debe ser factura o remisión.',
            'numero_documento.required' => 'Escriba el número del documento.',
            'numero_documento.max' => 'El número del documento admite máximo 60 caracteres.',
            'valor.required' => 'Escriba el valor recaudado.',
            'valor.numeric' => 'El valor debe ser un número.',
            'valor.gt' => 'El valor debe ser mayor que 0.',
            'valor.decimal' => 'El valor admite máximo 2 decimales.',
            'cuenta_pago_id.required' => 'Elija la cuenta de pago.',
            'observacion.max' => 'La observación admite máximo 500 caracteres.',
        ];
    }
}
