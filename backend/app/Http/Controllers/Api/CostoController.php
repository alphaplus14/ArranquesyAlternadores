<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CostoCompraService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S-71: historial de costos de compra por referencia o por código universal. Solo lectura y solo
 * en pantalla. Permiso `ver-historial-costos` (admin y logística) en la ruta.
 */
class CostoController extends Controller
{
    public function index(Request $request, CostoCompraService $costos): JsonResponse
    {
        $datos = $request->validate([
            'referencia_id' => ['required_without:codigo_universal_id', 'nullable', 'integer', 'exists:referencias,id'],
            'codigo_universal_id' => ['required_without:referencia_id', 'nullable', 'integer', 'exists:codigos_universales,id'],
            'proveedor_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [
            'referencia_id.required_without' => 'Elija una referencia o un código universal.',
            'codigo_universal_id.required_without' => 'Elija una referencia o un código universal.',
        ]);

        return response()->json($costos->historial(
            isset($datos['referencia_id']) ? (int) $datos['referencia_id'] : null,
            isset($datos['codigo_universal_id']) ? (int) $datos['codigo_universal_id'] : null,
            isset($datos['proveedor_id']) ? (int) $datos['proveedor_id'] : null,
            (int) ($datos['por_pagina'] ?? 20),
            (int) ($datos['page'] ?? 1),
        ));
    }
}
