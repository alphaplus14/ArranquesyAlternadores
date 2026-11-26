<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditoriaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** RF-11: historial de ediciones. Solo lectura. */
class AuditoriaController extends Controller
{
    public const TABLAS = ['referencias', 'codigos_universales', 'proveedores', 'categorias', 'clientes', 'ingresos', 'ingreso_items', 'users', 'venta_items'];

    public function index(Request $request, AuditoriaService $auditoria): JsonResponse
    {
        $datos = $request->validate([
            'tabla' => ['nullable', Rule::in(self::TABLAS)],
            'registro_id' => ['nullable', 'integer', 'required_with:tabla'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'], // S-70
        ]);

        // Página plana de Laravel (sin cambios) más `nombres`, de apoyo para la pantalla.
        return response()->json($auditoria->listar($datos['tabla'] ?? null, isset($datos['registro_id']) ? (int) $datos['registro_id'] : null, (int) ($datos['por_pagina'] ?? 50)));
    }
}
