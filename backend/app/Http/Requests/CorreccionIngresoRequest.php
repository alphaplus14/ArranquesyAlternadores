<?php

namespace App\Http\Requests;

use App\Enums\FormaPago;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * RF-28 (S-58): cuerpo de la corrección de un ingreso confirmado y de su vista previa. Todo es
 * opcional salvo el motivo (solo en la corrección): lo que no llega se conserva. `items`, si llega,
 * es el estado final de las líneas: una línea existente se nombra por su `id`; una sin `id` es nueva;
 * una existente que no aparece se quita. Proveedor, tipo e id no se corrigen (422). Las reglas de
 * negocio las repite IngresoService.
 */
class CorreccionIngresoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $esVistaPrevia = $this->routeIs('ingresos.correcciones.vista-previa');

        return [
            'proveedor_id' => ['prohibited'],
            'tipo' => ['prohibited'],
            'id' => ['prohibited'],
            'numero_documento' => ['sometimes', 'string', 'max:60'],
            'fecha' => ['sometimes', 'date'],
            'forma_pago' => ['sometimes', Rule::enum(FormaPago::class)],
            'vence_el' => ['sometimes', 'nullable', 'date'],
            'descuento_documento' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999999', 'prohibits:descuento_documento_porcentaje'],
            'descuento_documento_porcentaje' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100', 'prohibits:descuento_documento'],
            'items' => ['sometimes', 'array', 'max:500'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.referencia_id' => ['required_without:items.*.id', 'nullable', 'integer'],
            'items.*.cantidad' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.costo_unitario' => ['sometimes', 'numeric', 'min:0', 'max:9999999999'],
            'items.*.descuento_porcentaje' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'items.*.iva_porcentaje' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'confirmar_etiquetas_impresas' => ['sometimes', 'boolean'],
            'aceptar_diferencia' => ['sometimes', 'boolean'],
            'diferencia_motivo' => ['nullable', 'string', 'max:500'],
            'motivo' => $esVistaPrevia ? ['nullable', 'string', 'max:500'] : ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'proveedor_id.prohibited' => 'En una corrección no se cambia el proveedor del ingreso.',
            'tipo.prohibited' => 'En una corrección no se cambia el tipo (factura o remisión).',
            'id.prohibited' => 'En una corrección no se cambia el id del ingreso.',
            'motivo.required' => 'Escriba el motivo de la corrección.',
            'motivo.min' => 'El motivo debe tener al menos 5 caracteres.',
            'motivo.max' => 'El motivo admite máximo 500 caracteres.',
            'items.*.cantidad.min' => 'La cantidad debe ser al menos 1. Para quitar un ítem, sáquelo de la lista.',
            'items.*.referencia_id.required_without' => 'Elija la referencia del ítem nuevo.',
        ];
    }

    /** Datos para el servicio, sin el motivo (va aparte). */
    public function datosCorreccion(): array
    {
        return collect($this->validated())->except(['motivo'])->all();
    }
}
