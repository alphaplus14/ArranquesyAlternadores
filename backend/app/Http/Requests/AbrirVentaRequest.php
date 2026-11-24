<?php

namespace App\Http\Requests;

use App\Enums\FormaPago;
use Illuminate\Validation\Rule;

/**
 * RF-14: venta de contado (por defecto) o a crédito. Que el cliente de un crédito exista y esté
 * activo es regla de negocio y la decide VentaService::abrir.
 * S-46: la venta nace con su primer escaneo, así que exige SIEMPRE el payload de la lectura (las
 * mismas reglas que escanear): ningún camino HTTP crea una venta vacía.
 */
class AbrirVentaRequest extends EscanearVentaRequest
{
    public function rules(): array
    {
        return [
            'forma_pago' => ['sometimes', Rule::enum(FormaPago::class)],
            'cliente_id' => ['nullable', 'integer', 'exists:clientes,id'],
            'factura_contapyme' => ['nullable', 'string', 'max:40'],
            ...self::reglasEscaneo(),
        ];
    }

    public function formaPago(): FormaPago
    {
        return FormaPago::from($this->validated('forma_pago', FormaPago::Contado->value));
    }
}
