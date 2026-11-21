<?php

namespace App\Http\Requests;

use App\Enums\Rol;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Alta y edición de usuarios por el admin. Al editar, la contraseña nueva se confirma. */
class UsuarioRequest extends FormRequest
{
    public function rules(): array
    {
        $usuario = $this->route('usuario');

        if (! $usuario) {
            return [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', Password::min(8)],
                'rol' => ['required', Rule::enum(Rol::class)],
                'activo' => ['sometimes', 'boolean'],
            ];
        }

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users')->ignore($usuario->id)],
            'password' => ['sometimes', 'confirmed', Password::min(8)],
            'rol' => ['sometimes', Rule::enum(Rol::class)],
            'activo' => ['sometimes', 'boolean'],
        ];
    }
}
