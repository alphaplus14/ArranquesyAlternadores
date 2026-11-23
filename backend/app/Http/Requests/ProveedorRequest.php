<?php

namespace App\Http\Requests;

use App\Models\Proveedor;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * S-24 / RF-16 / S-29. El nombre es único sin importar mayúsculas ni tildes (lo resuelve la
 * colación utf8mb4_unicode_ci de la columna). El NIT es único comparando su forma sin puntos,
 * guiones ni espacios, pero se guarda como lo digitó el usuario (recortado). Si el nombre o el NIT
 * ya son de otro proveedor, el 422 trae proveedor_existente_id y su nombre para ofrecer "Usar ese
 * proveedor".
 *
 * S-29: el NIT es obligatorio al crear. Al editar, un NIT ya guardado no se puede borrar (solo
 * cambiar); un proveedor antiguo sin NIT puede editarse sin enviarlo. No se valida el dígito de
 * verificación: solo dígitos, puntos, guion y espacios, hasta 30. El asesor es opcional.
 */
class ProveedorRequest extends FormRequest
{
    public const FORMATO_NIT = '/^(?=.*\d)[\d.\-\s]+$/u';

    private ?Proveedor $existente = null;

    protected function prepareForValidation(): void
    {
        $limpio = [];
        foreach (['nombre', 'nit', 'asesor'] as $campo) {
            if ($this->has($campo) && is_string($this->input($campo))) {
                $limpio[$campo] = trim($this->input($campo)) === '' ? null : trim($this->input($campo));
            }
        }
        $this->merge($limpio);
    }

    public function rules(): array
    {
        $actual = $this->route('proveedor');
        $requerido = $actual ? 'sometimes' : 'required';

        // Crear: obligatorio. Editar: si ya tenía NIT, no se puede vaciar; si no tenía, puede omitirse.
        $nit = match (true) {
            $actual === null => ['required'],
            $actual->nit !== null => ['sometimes', 'required'],
            default => ['sometimes', 'nullable'],
        };

        return [
            'nombre' => [$requerido, 'string', 'max:150'],
            'nit' => [...$nit, 'string', 'max:30', 'regex:'.self::FORMATO_NIT],
            'asesor' => ['sometimes', 'nullable', 'string', 'max:120'],
            // RF-16: solo prellena el vencimiento de sus compras a crédito.
            'plazo_dias' => ['nullable', 'integer', 'min:0', 'max:365'],
        ];
    }

    public function messages(): array
    {
        return [
            'nit.required' => $this->route('proveedor')
                ? 'El NIT no se puede dejar vacío: cámbielo si está mal.'
                : 'Escriba el NIT del proveedor.',
            'nit.regex' => 'El NIT solo puede tener números, puntos, guion y espacios (ej.: 900123456-7).',
            'nit.max' => 'El NIT no puede tener más de 30 caracteres.',
            'asesor.max' => 'El nombre del asesor no puede tener más de 120 caracteres.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $actual = $this->route('proveedor');
            $otros = fn () => Proveedor::query()->when($actual, fn ($q) => $q->whereKeyNot($actual->id));

            // Cada duplicado se revisa si su propio campo no falló, para ofrecer "Usar ese
            // proveedor" aunque falte otro dato (por ejemplo, nombre repetido sin NIT).
            if (! $validator->errors()->has('nombre') && $this->filled('nombre')
                && ($mismo = $otros()->where('nombre', $this->input('nombre'))->first(['id', 'nombre']))) {
                $this->existente = $mismo;
                $validator->errors()->add('nombre', "Ya existe el proveedor {$mismo->nombre}.");

                return;
            }

            $nit = $validator->errors()->has('nit') ? '' : Proveedor::normalizarNit($this->input('nit'));
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
