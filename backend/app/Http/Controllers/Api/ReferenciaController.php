<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReferenciaRequest;
use App\Http\Resources\ReferenciaResource;
use App\Models\Referencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** RF-01 / RF-11. Catálogo con búsqueda y paginación en servidor. */
class ReferenciaController extends Controller
{
    private const RELACIONES = ['marca:id,nombre', 'proveedor:id,nombre', 'categoria:id,nombre,admite_codigo_universal', 'codigoUniversal:id,codigo'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = trim((string) $request->query('q'));

        $pagina = Referencia::query()
            ->with(self::RELACIONES)
            // Prefijo sobre el código (usa el índice) o texto completo sobre la descripción.
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('codigo_referencia', 'like', addcslashes($q, '%_\\').'%')
                ->orWhereFullText('descripcion', $q)))
            ->when($request->query('categoria_id'), fn ($query, $v) => $query->where('categoria_id', $v))
            ->when($request->query('marca_id'), fn ($query, $v) => $query->where('marca_id', $v))
            ->when($request->query('proveedor_id'), fn ($query, $v) => $query->where('proveedor_id', $v))
            ->when($request->query('codigo_universal_id'), fn ($query, $v) => $query->where('codigo_universal_id', $v))
            ->orderBy('codigo_referencia')
            ->paginate(min((int) $request->query('por_pagina', 25), 100));

        return ReferenciaResource::collection($pagina);
    }

    public function store(ReferenciaRequest $request): JsonResponse
    {
        $referencia = Referencia::create($request->validated());

        return (new ReferenciaResource($referencia->load(self::RELACIONES)))->response()->setStatusCode(201);
    }

    public function show(Referencia $referencia): ReferenciaResource
    {
        return new ReferenciaResource($referencia->load(self::RELACIONES));
    }

    public function update(ReferenciaRequest $request, Referencia $referencia): ReferenciaResource
    {
        $referencia->update($request->validated());

        return new ReferenciaResource($referencia->load(self::RELACIONES));
    }

    /** Con unidades o ingresos asociados, la FK restrict lo impide (409). */
    public function destroy(Referencia $referencia): JsonResponse
    {
        $referencia->delete();

        return response()->json(null, 204);
    }
}
