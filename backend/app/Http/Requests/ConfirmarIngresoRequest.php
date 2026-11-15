<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** RF-05 / RF-04: confirmar el ingreso; con factura_qr puede aceptar la diferencia contra el QR. */
class ConfirmarIngresoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'aceptar_diferencia' => ['sometimes', 'boolean'],
        ];
    }
}
