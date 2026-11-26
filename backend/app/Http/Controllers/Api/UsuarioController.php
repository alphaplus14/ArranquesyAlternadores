<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UsuarioRequest;
use App\Models\User;
use App\Services\UsuarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Gestión de usuarios (admin). No se borran: se desactivan, para no perder responsables. RF-11. */
class UsuarioController extends Controller
{
    public function __construct(private readonly UsuarioService $usuarios) {}

    public function index(Request $request): JsonResponse
    {
        // S-70: filas por página del usuario (50 por defecto, tope 100).
        return response()->json(User::orderBy('name')->paginate(min(max((int) $request->query('por_pagina', 50), 1), 100), ['id', 'name', 'usuario', 'rol', 'activo']));
    }

    public function store(UsuarioRequest $request): JsonResponse
    {
        $usuario = $this->usuarios->crear($request->validated());

        return response()->json($usuario->only('id', 'name', 'usuario', 'rol', 'activo'), 201);
    }

    public function update(UsuarioRequest $request, User $usuario): JsonResponse
    {
        $usuario = $this->usuarios->actualizar($usuario, $request->validated());

        return response()->json($usuario->only('id', 'name', 'usuario', 'rol', 'activo'));
    }
}
