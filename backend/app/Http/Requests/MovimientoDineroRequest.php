<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * RF-15 / RF-17: un abono de cliente o un pago a proveedor. Pesos enteros mayores que 0; la fecha
 * es hoy si no se envía, puede ser anterior y nunca futura. RF-24 (S-48): se elige la cuenta activa
 * (el medio se deriva de su tipo en el servicio).
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
            'cuenta_pago_id' => ['required', 'integer', Rule::exists('cuentas_pago', 'id')->where('activa', true)],
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
            'cuenta_pago_id.required' => 'Elija la cuenta de donde sale el pago.',
            'cuenta_pago_id.exists' => 'Elija una cuenta activa de la lista.',
        ];
    }
}
