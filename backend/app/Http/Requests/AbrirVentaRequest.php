<?php

namespace App\Http\Requests;

use App\Enums\FormaPago;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * RF-14: abrir una venta de contado (por defecto) o a crédito. Que el cliente de un crédito
 * exista y esté activo es regla de negocio y la decide VentaService::abrir.
 */
class AbrirVentaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'forma_pago' => ['sometimes', Rule::enum(FormaPago::class)],
            'cliente_id' => ['nullable', 'integer', 'exists:clientes,id'],
            'factura_contapyme' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function formaPago(): FormaPago
    {
        return FormaPago::from($this->validated('forma_pago', FormaPago::Contado->value));
    }
}
