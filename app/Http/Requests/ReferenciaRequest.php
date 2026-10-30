<?php

namespace App\Http\Requests;

use App\Models\Categoria;
use App\Models\CodigoUniversal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * RF-01: una referencia de una categoría que no admite código universal no puede tener
 * codigo_universal_id, y el universal debe ser de la misma categoría que la referencia.
 * Se valida sobre los valores efectivos (los enviados o, al editar, los ya guardados).
 */
class ReferenciaRequest extends FormRequest
{
    public function rules(): array
    {
        $referencia = $this->route('referencia');
        $requerido = $referencia ? 'sometimes' : 'required';

        return [
            'codigo_referencia' => [$requerido, 'string', 'max:80',
                Rule::unique('referencias')
                    ->where('proveedor_id', $this->input('proveedor_id', $referencia?->proveedor_id))
                    ->ignore($referencia?->id),
            ],
            'marca_id' => [$requerido, 'integer', 'exists:marcas,id'],
            'proveedor_id' => [$requerido, 'integer', 'exists:proveedores,id'],
            'categoria_id' => [$requerido, 'integer', 'exists:categorias,id'],
            'codigo_universal_id' => ['nullable', 'integer', 'exists:codigos_universales,id'],
            'descripcion' => [$requerido, 'string', 'max:255'],
            'precio_venta' => [$requerido, 'numeric', 'min:0', 'max:9999999999'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $referencia = $this->route('referencia');
            $universalId = $this->has('codigo_universal_id')
                ? $this->input('codigo_universal_id')
                : $referencia?->codigo_universal_id;

            if ($universalId === null) {
                return;
            }

            $categoria = Categoria::find($this->input('categoria_id', $referencia?->categoria_id));

            if (! $categoria->admite_codigo_universal) {
                $validator->errors()->add('codigo_universal_id', "La categoría {$categoria->nombre} no admite código universal (RF-01).");

                return;
            }

            if (CodigoUniversal::whereKey($universalId)->value('categoria_id') !== $categoria->id) {
                $validator->errors()->add('codigo_universal_id', 'El código universal pertenece a otra categoría.');
            }
        }];
    }
}
