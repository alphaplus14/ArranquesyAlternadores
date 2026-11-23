<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReferenciaResource;
use App\Models\Referencia;
use App\Services\StockMinimoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** RF-08: agotados por proveedor. RF-22: bajo mínimo. Solo en pantalla, sin exportar. */
class ReporteController extends Controller
{
    /** RF-22 (S-28): productos con existencia <= su mínimo efectivo, mayor faltante primero. */
    public function bajoMinimo(Request $request, StockMinimoService $servicio): JsonResponse
    {
        $filtros = $request->validate([
            'proveedor_id' => ['nullable', 'integer', 'exists:proveedores,id'],
            'categoria_id' => ['nullable', 'integer', 'exists:categorias,id'],
        ]);

        return response()->json($servicio->bajoMinimo($filtros, min((int) $request->query('por_pagina', 50), 200)));
    }

    public function agotados(Request $request): AnonymousResourceCollection
    {
        $datos = $request->validate([
            'proveedor_id' => ['required', 'integer', 'exists:proveedores,id'],
            'categoria_id' => ['nullable', 'integer', 'exists:categorias,id'], // RF-22: mismo filtro que Bajo mínimo
        ]);

        // Índice (proveedor_id, existencia).
        $pagina = Referencia::query()
            ->where('proveedor_id', $datos['proveedor_id'])
            ->where('existencia', 0)
            ->when($datos['categoria_id'] ?? null, fn ($q, $v) => $q->where('categoria_id', $v))
            ->activas() // S-26: una desactivada no es un agotado
            ->with(['categoria:id,nombre,admite_codigo_universal', 'codigoUniversal:id,codigo'])
            ->orderBy('codigo_referencia')
            ->paginate(min((int) $request->query('por_pagina', 50), 200));

        return ReferenciaResource::collection($pagina);
    }
}
