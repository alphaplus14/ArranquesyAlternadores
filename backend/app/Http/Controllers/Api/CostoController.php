<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CostoCompraService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S-71: costos de compra. Sin producto, todas las compras confirmadas con filtros (lo que traía
 * RF-32 / S-77, integrado aquí); con una referencia o un código universal, su historial con
 * último, anterior y variación. Solo lectura y solo en pantalla. Permiso `ver-historial-costos`
 * (admin y logística) en la ruta.
 */
class CostoController extends Controller
{
    public function index(Request $request, CostoCompraService $costos): JsonResponse
    {
        $datos = $request->validate([
            'referencia_id' => ['nullable', 'integer', 'exists:referencias,id'],
            'codigo_universal_id' => ['nullable', 'integer', 'exists:codigos_universales,id'],
            'proveedor_id' => ['nullable', 'integer', 'exists:proveedores,id'],
            'categoria_id' => ['nullable', 'integer', 'exists:categorias,id'],
            'q' => ['nullable', 'string', 'max:120'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'page' => ['nullable', 'integer', 'min:1'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [
            'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        ]);

        return response()->json($costos->historial(
            [
                'referencia_id' => isset($datos['referencia_id']) ? (int) $datos['referencia_id'] : null,
                'codigo_universal_id' => isset($datos['codigo_universal_id']) ? (int) $datos['codigo_universal_id'] : null,
                'proveedor_id' => isset($datos['proveedor_id']) ? (int) $datos['proveedor_id'] : null,
                'categoria_id' => isset($datos['categoria_id']) ? (int) $datos['categoria_id'] : null,
                'q' => trim((string) ($datos['q'] ?? '')) ?: null,
                'desde' => $datos['desde'] ?? null,
                'hasta' => $datos['hasta'] ?? null,
            ],
            (int) ($datos['por_pagina'] ?? 20),
            (int) ($datos['page'] ?? 1),
        ));
    }
}
