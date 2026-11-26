<?php

namespace App\Http\Requests;

use App\Services\PreferenciasService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * S-70: preferencias de pantalla. Lista blanca estricta de claves y valores; una clave desconocida
 * es 422. null en una clave la borra (vuelve al valor por defecto del rol).
 */
class PreferenciasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tamano_letra' => ['sometimes', 'nullable', Rule::in(PreferenciasService::TAMANOS_LETRA)],
            'densidad' => ['sometimes', 'nullable', Rule::in(PreferenciasService::DENSIDADES)],
            'acento' => ['sometimes', 'nullable', Rule::in(PreferenciasService::ACENTOS)],
            'filas_por_pagina' => ['sometimes', 'nullable', 'integer', Rule::in(PreferenciasService::FILAS_POR_PAGINA)],
            'menu_plegado' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'tamano_letra.in' => 'Elija un tamaño de letra de la lista.',
            'densidad.in' => 'Elija una densidad de la lista.',
            'acento.in' => 'Elija un color de la lista.',
            'filas_por_pagina.in' => 'Elija 25, 50 o 100 filas por página.',
            'filas_por_pagina.integer' => 'Elija 25, 50 o 100 filas por página.',
            'menu_plegado.boolean' => 'El menú plegado es sí o no.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            foreach (array_diff(array_keys($this->all()), PreferenciasService::CLAVES) as $clave) {
                $validator->errors()->add((string) $clave, 'Esta preferencia no existe.');
            }
        }];
    }
}
