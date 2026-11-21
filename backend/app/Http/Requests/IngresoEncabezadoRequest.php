<?php

namespace App\Http\Requests;

use App\Enums\FormaPago;
use App\Enums\TipoIngreso;
use App\Models\Ingreso;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * RF-04 / RF-05 / RF-09 / RF-16: encabezado del ingreso, al crear (POST) o editar el borrador (PUT/PATCH).
 * A crédito, el vencimiento es obligatorio y no puede ser anterior a la fecha del documento.
 * Se valida sobre los valores efectivos: los enviados o, al editar, los ya guardados.
 */
class IngresoEncabezadoRequest extends FormRequest
{
    public function rules(): array
    {
        $ingreso = $this->route('ingreso');
        $requerido = $ingreso ? 'sometimes' : 'required';

        return [
            // El tipo se fija al crear: cambiarlo cambiaría de qué documento se trata.
            ...($ingreso ? [] : ['tipo' => ['required', Rule::enum(TipoIngreso::class)]]),
            'proveedor_id' => [$requerido, 'integer', 'exists:proveedores,id'],
            'numero_documento' => [$requerido, 'string', 'max:60'],
            'fecha' => [$requerido, 'date'],
            // RF-09 / S-22: el descuento del documento llega en pesos o en %, no ambos.
            'descuento_documento' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999999', 'prohibits:descuento_documento_porcentaje'],
            'descuento_documento_porcentaje' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100', 'prohibits:descuento_documento'],
            'forma_pago' => ['sometimes', Rule::enum(FormaPago::class)], // RF-16
            'vence_el' => ['nullable', 'date'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Ingreso|null $ingreso */
            $ingreso = $this->route('ingreso');
            $forma = $this->has('forma_pago')
                ? FormaPago::from($this->input('forma_pago'))
                : ($ingreso?->forma_pago ?? FormaPago::Contado);

            if ($forma !== FormaPago::Credito) {
                return;
            }

            $vence = $this->has('vence_el') ? $this->input('vence_el') : $ingreso?->vence_el?->toDateString();
            $fecha = $this->input('fecha', $ingreso?->fecha?->toDateString());

            if (blank($vence)) {
                $validator->errors()->add('vence_el', 'A crédito, escriba la fecha de vencimiento que trae el documento.');

                return;
            }

            if (Carbon::parse($vence)->lt(Carbon::parse($fecha))) {
                $validator->errors()->add('vence_el', 'El vencimiento no puede ser anterior a la fecha del documento.');
            }
        }];
    }
}
