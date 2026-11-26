<?php

namespace App\Http\Requests;

use App\Enums\FormaAbonoEmpleado;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * RF-27 (S-56): préstamo o abono de un empleado (solo mostrador). Pesos enteros > 0. La fecha es
 * siempre hoy (la pone el servicio): el movimiento entra a la caja del día en que se registra. El
 * abono lleva forma (S-81: si no llega, `caja`); la cuenta la exige el servicio solo si la forma es caja.
 */
class MovimientoEmpleadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** S-81: un abono sin forma es por caja (la nómina se elige como opción de la cuenta de pago). */
    protected function prepareForValidation(): void
    {
        if ($this->routeIs('empleados.abonos') && ! $this->filled('forma')) {
            $this->merge(['forma' => FormaAbonoEmpleado::Caja->value]);
        }
    }

    public function rules(): array
    {
        $esAbono = $this->routeIs('empleados.abonos');

        return [
            'fecha' => ['prohibited'],
            'valor' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'nota' => ['nullable', 'string', 'max:255'],
            ...($esAbono ? [
                'forma' => ['required', Rule::enum(FormaAbonoEmpleado::class)],
                'cuenta_pago_id' => ['nullable', 'integer'],
            ] : [
                'forma' => ['prohibited'],
                'cuenta_pago_id' => ['prohibited'],
            ]),
        ];
    }

    public function messages(): array
    {
        return [
            'fecha.prohibited' => 'La fecha es la de hoy: los préstamos y abonos se registran el día en que ocurren.',
            'valor.required' => 'Escriba el valor.',
            'valor.integer' => 'El valor va en pesos enteros, sin centavos.',
            'valor.min' => 'El valor debe ser mayor que 0.',
            'forma.required' => 'Elija si el abono es por caja o por nómina.',
            'forma.enum' => 'Elija si el abono es por caja o por nómina.',
            'forma.prohibited' => 'Un préstamo no lleva forma: sale siempre del efectivo.',
            'cuenta_pago_id.prohibited' => 'Un préstamo sale siempre de la cuenta Efectivo.',
            'nota.max' => 'La nota admite máximo 255 caracteres.',
        ];
    }
}
