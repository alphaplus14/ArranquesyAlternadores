<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * RF-24 (S-48): cierre de una venta. De contado exige `pagos` = [{cuenta_pago_id, valor, referencia?}];
 * que las cuentas estén activas, que la suma sea el total y que el crédito no traiga pagos lo decide
 * VentaService::cerrar (regla de negocio, con la venta bloqueada).
 */
class CerrarVentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pagos' => ['sometimes', 'array', 'max:'.config('caja.max_pagos_por_venta')],
            'pagos.*.cuenta_pago_id' => ['required', 'integer'],
            'pagos.*.valor' => ['required', 'numeric', 'gt:0', 'max:999999999999', 'decimal:0,2'],
            'pagos.*.referencia' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return [
            'pagos.array' => 'La forma de pago no es válida.',
            'pagos.max' => 'El pago se puede dividir en máximo :max cuentas.',
            'pagos.*.cuenta_pago_id.required' => 'Elija la cuenta de cada pago.',
            'pagos.*.valor.required' => 'Escriba el valor de cada pago.',
            'pagos.*.valor.numeric' => 'El valor del pago debe ser un número.',
            'pagos.*.valor.gt' => 'Cada valor del pago debe ser mayor que 0.',
            'pagos.*.valor.decimal' => 'El valor del pago admite máximo 2 decimales.',
            'pagos.*.referencia.max' => 'La referencia del pago admite máximo 60 caracteres.',
        ];
    }

    /** @return list<array{cuenta_pago_id: int, valor: string, referencia: ?string}> */
    public function pagos(): array
    {
        return array_values(array_map(fn ($p) => [
            'cuenta_pago_id' => (int) $p['cuenta_pago_id'],
            'valor' => (string) $p['valor'],
            'referencia' => $p['referencia'] ?? null,
        ], $this->validated('pagos') ?? []));
    }
}
