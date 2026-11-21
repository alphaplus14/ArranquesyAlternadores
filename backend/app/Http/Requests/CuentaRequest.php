<?php

namespace App\Http\Requests;

use App\Rules\NombreUsuario;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Cuenta propia del admin: cambiar el usuario o la contraseña exige la contraseña actual. */
class CuentaRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('usuario')) {
            $this->merge(['usuario' => NombreUsuario::normalizar($this->input('usuario'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'usuario' => ['sometimes', new NombreUsuario, Rule::unique('users', 'usuario')->ignore($this->user()->id)],
            'email' => ['prohibited'],
            'password' => ['sometimes', 'confirmed', Password::min(8)],
            'password_actual' => ['required_with:usuario,password', 'string', 'current_password:web'],
        ];
    }

    public function messages(): array
    {
        return [
            'usuario.unique' => 'Ya existe una cuenta con ese usuario.',
            'email.prohibited' => 'Las cuentas ya no usan correo: envíe el campo usuario.',
            'password_actual.required_with' => 'Escriba su contraseña actual para cambiar el usuario o la contraseña.',
            'password_actual.current_password' => 'La contraseña actual no es correcta.',
        ];
    }
}
