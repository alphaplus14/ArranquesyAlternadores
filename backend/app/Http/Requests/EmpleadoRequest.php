<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * RF-27 (S-55): crear (POST) o editar (PUT/PATCH) un empleado. Solo admin (ruta). El saldo de
 * apertura solo se acepta al crear: una vez (S-55).
 */
class EmpleadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $empleado = $this->route('empleado');
        $creando = $empleado === null;
        $parcial = $creando ? 'required' : 'sometimes';

        return [
            'nombre' => [$parcial, 'string', 'min:2', 'max:120', Rule::unique('empleados', 'nombre')->ignore($empleado?->id)],
            'documento' => ['nullable', 'string', 'max:30', Rule::unique('empleados', 'documento')->ignore($empleado?->id)],
            'activo' => ['sometimes', 'boolean'],
            'saldo_apertura' => $creando ? ['nullable', 'integer', 'min:1', 'max:999999999999'] : ['prohibited'],
            'fecha_apertura' => $creando ? ['nullable', 'date_format:Y-m-d'] : ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'Escriba el nombre del empleado.',
            'nombre.unique' => 'Ya existe un empleado con ese nombre.',
            'nombre.max' => 'El nombre admite máximo 120 caracteres.',
            'documento.unique' => 'Ya existe un empleado con ese documento.',
            'documento.max' => 'El documento admite máximo 30 caracteres.',
            'saldo_apertura.integer' => 'El saldo de apertura va en pesos enteros, sin centavos.',
            'saldo_apertura.min' => 'El saldo de apertura debe ser mayor que 0.',
            'saldo_apertura.prohibited' => 'El saldo de apertura solo se registra al crear el empleado.',
            'fecha_apertura.prohibited' => 'El saldo de apertura solo se registra al crear el empleado.',
        ];
    }
}
