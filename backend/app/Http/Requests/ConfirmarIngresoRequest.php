<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * RF-05 / RF-04: confirmar el ingreso. Con factura_qr puede aceptar la diferencia contra el QR;
 * el motivo es obligatorio cuando la diferencia supera la tolerancia (lo decide IngresoService).
 */
class ConfirmarIngresoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'aceptar_diferencia' => ['sometimes', 'boolean'],
            'diferencia_motivo' => ['nullable', 'string', 'max:500'],
        ];
    }
}
