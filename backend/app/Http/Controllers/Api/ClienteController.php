<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClienteRequest;
use App\Http\Resources\ClienteResource;
use App\Models\Cliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * RF-13: clientes. Listar (los tres roles), crear (admin y mostrador), editar (solo admin).
 * No hay DELETE: un cliente se desactiva con `activo = false`.
 */
class ClienteController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = trim((string) $request->query('q', ''));

        $pagina = Cliente::query()
            ->when($q !== '', fn ($consulta) => $consulta->where(fn ($w) => $w
                ->where('nombre', 'like', "{$q}%")
                ->orWhere('documento', 'like', "{$q}%")))
            ->when($request->has('activo'), fn ($consulta) => $consulta->where('activo', $request->boolean('activo')))
            ->orderBy('nombre')
            ->paginate(min(max((int) $request->query('por_pagina', 25), 1), 100));

        return ClienteResource::collection($pagina);
    }

    public function store(ClienteRequest $request): JsonResponse
    {
        return (new ClienteResource(Cliente::create($request->validated())->refresh()))
            ->response()->setStatusCode(201);
    }

    public function update(ClienteRequest $request, Cliente $cliente): ClienteResource
    {
        $cliente->update($request->validated());

        return new ClienteResource($cliente);
    }
}
