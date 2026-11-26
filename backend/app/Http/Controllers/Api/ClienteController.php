<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClienteRequest;
use App\Http\Resources\ClienteResource;
use App\Models\Cliente;
use App\Services\ClienteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * RF-13: clientes. Listar (los tres roles), crear (admin y mostrador), editar (solo admin).
 * No hay DELETE: un cliente se desactiva con `activo = false`.
 */
class ClienteController extends Controller
{
    /**
     * Listado paginado (admin y pantallas de consulta). Con `limite` es la búsqueda rápida del
     * selector de la venta a crédito (S-72): exige `q` de 2 caracteres o más (422 si no) y devuelve
     * hasta 8 activos, sin paginar, nombre que contiene y documento por prefijo. Con `documento` filtra por documento exacto (activos o no), para
     * ofrecer el cliente existente cuando un alta choca con su documento.
     */
    public function index(Request $request, ClienteService $clientes): AnonymousResourceCollection
    {
        $request->validate([
            'limite' => ['sometimes', 'integer', 'min:1', 'max:'.ClienteService::LIMITE_BUSQUEDA],
            // Solo la búsqueda rápida exige el mínimo; el listado paginado de admin no cambia.
            'q' => [Rule::requiredIf($request->has('limite')), Rule::when($request->has('limite'), ['string', 'min:'.ClienteService::MIN_CARACTERES, 'max:150'])],
            'documento' => ['sometimes', 'string', 'max:30'],
        ]);
        $q = trim((string) $request->query('q', ''));

        if ($request->has('limite')) {
            return ClienteResource::collection($clientes->buscar($q, (int) $request->query('limite')));
        }

        $pagina = Cliente::query()
            ->when($request->filled('documento'), fn ($consulta) => $consulta->where('documento', trim((string) $request->query('documento'))))
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
