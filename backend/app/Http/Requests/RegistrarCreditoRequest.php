<?php

namespace App\Http\Requests;

use App\Enums\TipoDocumentoVenta;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * RF-14 cambiado (S-73): venta a crédito de solo valor. Cliente activo, tipo de documento y valor
 * total con IVA (> 0, máximo 2 decimales); número del documento opcional. El servicio repite las
 * reglas de negocio (cliente activo, número vigente repetido, día de caja abierto).
 */
class RegistrarCreditoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cliente_id' => ['required', 'integer', Rule::exists('clientes', 'id')->where('activo', true)],
            'tipo_documento' => ['required', Rule::enum(TipoDocumentoVenta::class)],
            'valor_total' => ['required', 'numeric', 'gt:0', 'max:999999999999', 'decimal:0,2'],
            'numero_documento' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function messages(): array
    {
        return [
            'cliente_id.required' => 'Elija el cliente.',
            'cliente_id.exists' => 'Elija un cliente activo.',
            'tipo_documento.required' => 'Elija si es factura o remisión.',
            'tipo_documento.enum' => 'Elija si es factura o remisión.',
            'valor_total.required' => 'Escriba el valor total.',
            'valor_total.numeric' => 'El valor total debe ser un número.',
            'valor_total.gt' => 'El valor total debe ser mayor que 0.',
            'valor_total.decimal' => 'El valor total admite máximo 2 decimales.',
            'numero_documento.max' => 'El número admite máximo 40 caracteres.',
        ];
    }
}
