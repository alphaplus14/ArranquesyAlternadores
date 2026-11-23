<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CarteraService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RF-15 / RF-18 (S-30): cartera de clientes. Admin y mostrador; logística 403 (ruta). Solo en
 * pantalla: sin exportar.
 */
class CarteraController extends Controller
{
    public function __construct(private readonly CarteraService $cartera) {}

    /** Clientes con saldo, vencido y próximo vencimiento, mayor saldo primero, con totales. */
    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'busqueda' => ['nullable', 'string', 'max:150'],
            'solo_con_saldo' => ['nullable', 'boolean'],
            'solo_vencidos' => ['nullable', 'boolean'],
            'corte' => ['nullable', 'date_format:Y-m-d'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $resultado = $this->cartera->listar([
            'busqueda' => $datos['busqueda'] ?? null,
            'solo_con_saldo' => $request->boolean('solo_con_saldo'),
            'solo_vencidos' => $request->boolean('solo_vencidos'),
            'corte' => $datos['corte'] ?? null,
        ], (int) ($datos['por_pagina'] ?? 25));

        return response()->json([
            ...$resultado['pagina']->toArray(),
            'totales' => $resultado['totales'],
            'corte' => $resultado['corte'],
        ]);
    }
}
