<?php

namespace App\Http\Requests;

/**
 * RF-17: pago a un proveedor. RF-17 (ajuste): `ingreso_id` opcional para aplicarlo a una factura
 * elegida; las reglas de negocio (proveedor, crédito, pendiente) las valida PagoService.
 */
class PagoProveedorRequest extends MovimientoDineroRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'ingreso_id' => ['nullable', 'integer'],
        ];
    }
}
