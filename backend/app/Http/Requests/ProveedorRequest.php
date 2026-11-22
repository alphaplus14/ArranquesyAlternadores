<?php

namespace App\Http\Requests;

use App\Models\Proveedor;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * S-24 / RF-16. El nombre es único sin importar mayúsculas ni tildes (lo resuelve la colación
 * utf8mb4_unicode_ci de la columna). El NIT es único comparando su forma sin puntos, guiones ni
 * espacios, pero se guarda como lo digitó el usuario (recortado). Si el nombre o el NIT ya son de
 * otro proveedor, el 422 trae proveedor_existente_id y su nombre para ofrecer "Usar ese proveedor".
 */
class ProveedorRequest extends FormRequest
{
    private ?Proveedor $existente = null;

    protected function prepareForValidation(): void
    {
        $limpio = [];
        foreach (['nombre', 'nit'] as $campo) {
            if ($this->has($campo) && is_string($this->input($campo))) {
                $limpio[$campo] = trim($this->input($campo)) === '' ? null : trim($this->input($campo));
            }
        }
        $this->merge($limpio);
    }

    public function rules(): array
    {
        $requerido = $this->route('proveedor') ? 'sometimes' : 'required';

        return [
            'nombre' => [$requerido, 'string', 'max:150'],
            'nit' => ['nullable', 'string', 'max:30'],
            // RF-16: solo prellena el vencimiento de sus compras a crédito.
            'plazo_dias' => ['nullable', 'integer', 'min:0', 'max:365'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $actual = $this->route('proveedor');
            $otros = fn () => Proveedor::query()->when($actual, fn ($q) => $q->whereKeyNot($actual->id));

            if ($this->filled('nombre') && ($mismo = $otros()->where('nombre', $this->input('nombre'))->first(['id', 'nombre']))) {
                $this->existente = $mismo;
                $validator->errors()->add('nombre', "Ya existe el proveedor {$mismo->nombre}.");

                return;
            }

            $nit = Proveedor::normalizarNit($this->input('nit'));
            if ($nit !== '') {
                $mismo = $otros()->whereNotNull('nit')
                    ->whereRaw("LOWER(REPLACE(REPLACE(REPLACE(nit, '.', ''), '-', ''), ' ', '')) = ?", [$nit])
                    ->first(['id', 'nombre']);
                if ($mismo) {
                    $this->existente = $mismo;
                    $validator->errors()->add('nit', "Ese NIT ya es del proveedor {$mismo->nombre}.");
                }
            }
        }];
    }

    protected function failedValidation(Validator $validator): void
    {
        $cuerpo = ['message' => $validator->errors()->first(), 'errors' => $validator->errors()];
        if ($this->existente) {
            $cuerpo['proveedor_existente_id'] = $this->existente->id;
            $cuerpo['proveedor_existente'] = $this->existente->nombre;
        }

        throw new ValidationException($validator, response()->json($cuerpo, 422));
    }
}
