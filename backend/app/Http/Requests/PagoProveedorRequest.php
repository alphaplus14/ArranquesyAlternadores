<?php

namespace App\Http\Requests;

/**
 * RF-17: pago a un proveedor. S-79: `ingreso_id` obligatorio, todo pago nuevo va a una factura o
 * remisión a crédito elegida (sin FIFO). Las reglas de negocio (proveedor, crédito, pendiente) las
 * valida PagoService con el documento bloqueado.
 */
class PagoProveedorRequest extends MovimientoDineroRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'ingreso_id' => ['required', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'ingreso_id.required' => 'Elija la factura que va a pagar.',
        ];
    }
}
