<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * RF-04: contenido leído del QR de la factura de compra. Los demás campos solo se usan si el QR
 * no los trae (por ejemplo, un QR con solo la URL de la DIAN).
 */
class IngresoQrRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'contenido' => ['required', 'string', 'max:4000'],
            'proveedor_id' => ['nullable', 'integer', 'exists:proveedores,id'],
            'numero_documento' => ['nullable', 'string', 'max:60'],
            'fecha' => ['nullable', 'date'],
        ];
    }
}
