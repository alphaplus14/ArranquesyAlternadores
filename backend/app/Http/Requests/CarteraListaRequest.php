<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * RF-15 / RF-18: filtros de GET /api/cartera. `corte` = saldo a ese día; `desde`/`hasta` = ventas
 * a crédito cerradas dentro del período (el saldo sigue siendo el neto vigente al corte).
 */
class CarteraListaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el rol lo controla el middleware de la ruta
    }

    public function rules(): array
    {
        return [
            'busqueda' => ['nullable', 'string', 'max:150'],
            'solo_con_saldo' => ['nullable', 'boolean'],
            'solo_vencidos' => ['nullable', 'boolean'],
            'corte' => ['nullable', 'date_format:Y-m-d'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
