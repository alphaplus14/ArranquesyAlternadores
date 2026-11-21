<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Cuenta propia del admin: cambiar correo o contraseña exige la contraseña actual. */
class CuentaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users')->ignore($this->user()->id)],
            'password' => ['sometimes', 'confirmed', Password::min(8)],
            'password_actual' => ['required_with:email,password', 'string', 'current_password:web'],
        ];
    }

    public function messages(): array
    {
        return [
            'password_actual.required_with' => 'Escriba su contraseña actual para cambiar el correo o la contraseña.',
            'password_actual.current_password' => 'La contraseña actual no es correcta.',
        ];
    }
}
