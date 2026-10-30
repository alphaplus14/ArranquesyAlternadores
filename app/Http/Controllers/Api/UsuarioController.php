<?php

namespace App\Http\Controllers\Api;

use App\Enums\Rol;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Gestión de usuarios (admin). No se borran: se desactivan, para no perder responsables. RF-11. */
class UsuarioController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(User::orderBy('name')->paginate(50, ['id', 'name', 'email', 'rol', 'activo']));
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
            'rol' => ['required', Rule::enum(Rol::class)],
            'activo' => ['sometimes', 'boolean'],
        ]);

        $usuario = User::create($datos);

        return response()->json($usuario->only('id', 'name', 'email', 'rol', 'activo'), 201);
    }

    public function update(Request $request, User $usuario): JsonResponse
    {
        $datos = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users')->ignore($usuario->id)],
            'password' => ['sometimes', Password::min(8)],
            'rol' => ['sometimes', Rule::enum(Rol::class)],
            'activo' => ['sometimes', 'boolean'],
        ]);

        $usuario->update($datos);

        return response()->json($usuario->only('id', 'name', 'email', 'rol', 'activo'));
    }
}
