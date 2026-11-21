<?php

namespace App\Http\Requests;

use App\Enums\Rol;
use App\Rules\NombreUsuario;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Alta y edición de usuarios por el admin. Al editar, la contraseña nueva se confirma. */
class UsuarioRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('usuario')) {
            $this->merge(['usuario' => NombreUsuario::normalizar($this->input('usuario'))]);
        }
    }

    public function rules(): array
    {
        $usuario = $this->route('usuario');
        $requerido = $usuario ? 'sometimes' : 'required';

        return [
            'name' => [$requerido, 'string', 'max:255'],
            'usuario' => [$requerido, new NombreUsuario, Rule::unique('users', 'usuario')->ignore($usuario?->id)],
            'email' => ['prohibited'],
            'password' => $usuario ? ['sometimes', 'confirmed', Password::min(8)] : ['required', Password::min(8)],
            'rol' => [$requerido, Rule::enum(Rol::class)],
            'activo' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'usuario.unique' => 'Ya existe una cuenta con ese usuario.',
            'email.prohibited' => 'Las cuentas ya no usan correo: envíe el campo usuario.',
        ];
    }
}
