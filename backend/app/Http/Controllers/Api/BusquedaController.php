<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReferenciaResource;
use App\Http\Resources\UnidadResource;
use App\Services\BusquedaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RF-12: respaldo cuando el escáner no lee. Busca por código de barras (exacto), por código de
 * referencia o universal (prefijo) y por descripción (FULLTEXT). El orden lo define BusquedaService.
 */
class BusquedaController extends Controller
{
    public function __invoke(Request $request, BusquedaService $busqueda): JsonResponse
    {
        $q = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']])['q']);

        ['unidad' => $unidad, 'referencias' => $referencias] = $busqueda->buscar($q);

        return response()->json([
            'unidad' => $unidad ? new UnidadResource($unidad) : null,
            'referencias' => ReferenciaResource::collection($referencias),
        ]);
    }
}
