<?php

namespace App\Http\Requests;

use App\Enums\Rol;
use App\Models\Categoria;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * RF-01 / S-24. Nombre único sin importar mayúsculas ni tildes (colación utf8mb4_unicode_ci).
 * Regla 1: el código universal es solo para ciertas categorías y lo decide el admin. Si quien
 * crea es logística, admite_codigo_universal se ignora y queda false (no es un error).
 */
class CategoriaRequest extends FormRequest
{
    private ?Categoria $existente = null;

    protected function prepareForValidation(): void
    {
        if ($this->has('nombre') && is_string($this->input('nombre'))) {
            $this->merge(['nombre' => trim($this->input('nombre'))]);
        }
        if ($this->user()?->rol !== Rol::Admin) {
            $this->merge(['admite_codigo_universal' => false]);
        }
    }

    public function rules(): array
    {
        $requerido = $this->route('categoria') ? 'sometimes' : 'required';

        return [
            'nombre' => [$requerido, 'string', 'max:100'],
            'admite_codigo_universal' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('nombre')) {
                return;
            }
            $actual = $this->route('categoria');
            $mismo = Categoria::query()
                ->when($actual, fn ($q) => $q->whereKeyNot($actual->id))
                ->where('nombre', $this->input('nombre'))
                ->first(['id', 'nombre']);
            if ($mismo) {
                $this->existente = $mismo;
                $validator->errors()->add('nombre', "Ya existe la categoría {$mismo->nombre}.");
            }
        }];
    }

    protected function failedValidation(Validator $validator): void
    {
        $cuerpo = ['message' => $validator->errors()->first(), 'errors' => $validator->errors()];
        if ($this->existente) {
            $cuerpo['categoria_existente_id'] = $this->existente->id;
            $cuerpo['categoria_existente'] = $this->existente->nombre;
        }

        throw new ValidationException($validator, response()->json($cuerpo, 422));
    }
}
