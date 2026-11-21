<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** RF-13: crear (admin y mostrador) o editar (solo admin; lo garantiza la ruta). */
class ClienteRequest extends FormRequest
{
    public function rules(): array
    {
        $cliente = $this->route('cliente');
        $requerido = $cliente ? 'sometimes' : 'required';

        return [
            'nombre' => [$requerido, 'string', 'max:150'],
            'documento' => ['nullable', 'string', 'max:30', Rule::unique('clientes')->ignore($cliente?->id)],
            'telefono' => ['nullable', 'string', 'max:30'],
            'plazo_dias' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'activo' => ['sometimes', 'boolean'],
        ];
    }
}
