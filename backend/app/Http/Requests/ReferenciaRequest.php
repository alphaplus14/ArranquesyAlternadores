<?php

namespace App\Http\Requests;

use App\Enums\Calidad;
use App\Models\Categoria;
use App\Models\CodigoUniversal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * RF-01: una referencia de una categoría que no admite código universal no puede tener
 * codigo_universal_id, y el universal debe ser de la misma categoría que la referencia.
 * Se valida sobre los valores efectivos (los enviados o, al editar, los ya guardados).
 *
 * S-25: codigo_fabricante (código de barras o QR del producto) es opcional y único por proveedor.
 * Si la referencia llega vacía y hay código de barras, la referencia es ese mismo código. Los dos
 * se recortan; vacío equivale a no tenerlo.
 */
class ReferenciaRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $limpio = [];
        foreach (['codigo_referencia', 'codigo_fabricante'] as $campo) {
            if ($this->has($campo)) {
                $valor = is_string($this->input($campo)) ? trim($this->input($campo)) : $this->input($campo);
                $limpio[$campo] = $valor === '' ? null : $valor;
            }
        }
        $this->merge($limpio);

        // S-25: sin otra referencia, el código de barras hace de referencia.
        $fabricante = $this->input('codigo_fabricante', $this->route('referencia')?->codigo_fabricante);
        $refVacia = $this->has('codigo_referencia') ? $this->input('codigo_referencia') === null : $this->route('referencia') === null;
        if ($refVacia && is_string($fabricante) && $fabricante !== '') {
            $this->merge(['codigo_referencia' => $fabricante]);
        }
    }

    public function rules(): array
    {
        $referencia = $this->route('referencia');
        $requerido = $referencia ? 'sometimes' : 'required';
        $proveedorId = $this->input('proveedor_id', $referencia?->proveedor_id);

        return [
            'codigo_referencia' => [$requerido, 'string', 'max:80',
                Rule::unique('referencias')->where('proveedor_id', $proveedorId)->ignore($referencia?->id),
            ],
            'codigo_fabricante' => ['sometimes', 'nullable', 'string', 'max:120',
                Rule::unique('referencias')->where('proveedor_id', $proveedorId)->ignore($referencia?->id),
            ],
            'proveedor_id' => [$requerido, 'integer', 'exists:proveedores,id'],
            'categoria_id' => [$requerido, 'integer', 'exists:categorias,id'],
            'codigo_universal_id' => ['nullable', 'integer', 'exists:codigos_universales,id'],
            'calidad' => ['sometimes', 'nullable', Rule::enum(Calidad::class)], // S-23: fino | generico
            // S-23: el sistema no maneja marcas; un cliente viejo que la mande recibe 422.
            'marca_id' => ['prohibited'],
            'descripcion' => [$requerido, 'string', 'max:255'],
            'precio_venta' => [$requerido, 'numeric', 'gt:0', 'max:9999999999'], // RF-14: ningún precio puede ser 0
            'iva_venta_porcentaje' => ['sometimes', 'numeric', Rule::in([0, 19])], // RF-14 / S-15
        ];
    }

    public function messages(): array
    {
        return [
            'marca_id.prohibited' => 'El sistema no maneja marcas: la referencia se distingue por código, proveedor y calidad (S-23).',
            'codigo_referencia.required' => 'Escriba la referencia del proveedor o el código de barras del producto.',
            'codigo_referencia.unique' => 'Este proveedor ya tiene una referencia con ese código.',
            'codigo_fabricante.unique' => 'Este proveedor ya tiene otra referencia con ese código de barras.',
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
