<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UsuarioRequest;
use App\Models\User;
use App\Services\UsuarioService;
use Illuminate\Http\JsonResponse;

/** Gestión de usuarios (admin). No se borran: se desactivan, para no perder responsables. RF-11. */
class UsuarioController extends Controller
{
    public function __construct(private readonly UsuarioService $usuarios) {}

    public function index(): JsonResponse
    {
        return response()->json(User::orderBy('name')->paginate(50, ['id', 'name', 'email', 'rol', 'activo']));
    }

    public function store(UsuarioRequest $request): JsonResponse
    {
        $usuario = $this->usuarios->crear($request->validated());

        return response()->json($usuario->only('id', 'name', 'email', 'rol', 'activo'), 201);
    }

    public function update(UsuarioRequest $request, User $usuario): JsonResponse
    {
        $usuario = $this->usuarios->actualizar($usuario, $request->validated());

        return response()->json($usuario->only('id', 'name', 'email', 'rol', 'activo'));
    }
}
