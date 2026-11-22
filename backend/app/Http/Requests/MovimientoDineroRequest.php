<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * RF-15 / RF-17: un abono de cliente o un pago a proveedor. Pesos enteros mayores que 0; la fecha
 * es hoy si no se envía, puede ser anterior y nunca futura; el medio sale de config('cartera.medios').
 */
class MovimientoDineroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fecha' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today'],
            'valor' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'medio' => ['required', Rule::in(array_keys(config('cartera.medios')))],
            'referencia' => ['nullable', 'string', 'max:60'],
            'nota' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'fecha.before_or_equal' => 'La fecha no puede ser futura.',
            'valor.integer' => 'El valor va en pesos enteros, sin centavos.',
            'valor.min' => 'El valor debe ser mayor que 0.',
            'medio.in' => 'Elija un medio de pago de la lista.',
        ];
    }
}
