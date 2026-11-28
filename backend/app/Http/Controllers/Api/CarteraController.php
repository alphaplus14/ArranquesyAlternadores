<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AbonoResource;
use App\Http\Requests\CarteraListaRequest;
use App\Models\Cliente;
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
    public function index(CarteraListaRequest $request): JsonResponse
    {
        $datos = $request->validated();

        $resultado = $this->cartera->listar([
            'busqueda' => $datos['busqueda'] ?? null,
            'solo_con_saldo' => $request->boolean('solo_con_saldo'),
            'solo_vencidos' => $request->boolean('solo_vencidos'),
            'corte' => $datos['corte'] ?? null,
            'desde' => $datos['desde'] ?? null,
            'hasta' => $datos['hasta'] ?? null,
        ], (int) ($datos['por_pagina'] ?? 25));

        return response()->json([
            ...$resultado['pagina']->toArray(),
            'totales' => $resultado['totales'],
            'corte' => $resultado['corte'],
            'desde' => $resultado['desde'],
            'hasta' => $resultado['hasta'],
        ]);
    }

    /** RF-18: ventas a crédito del cliente (FIFO por venta), abonos, saldo y antigüedad. */
    public function show(Request $request, Cliente $cliente): JsonResponse
    {
        $datos = $request->validate([
            'corte' => ['nullable', 'date_format:Y-m-d'],
            'busqueda' => ['nullable', 'string', 'max:60'],
            'estado' => ['nullable', 'in:todas,pendientes,vencidas,pagadas'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ]);
        $detalle = $this->cartera->detalle($cliente, $datos['corte'] ?? null, [
            'busqueda' => $datos['busqueda'] ?? null,
            'estado' => $datos['estado'] ?? 'todas',
            'desde' => $datos['desde'] ?? null,
            'hasta' => $datos['hasta'] ?? null,
        ]);

        return response()->json([...$detalle, 'abonos' => AbonoResource::collection($detalle['abonos'])]);
    }

    /** RF-18: estado de cuenta en orden de fecha con saldo acumulado. */
    public function estadoCuenta(Request $request, Cliente $cliente): JsonResponse
    {
        $datos = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ]);

        return response()->json($this->cartera->estadoCuenta($cliente, $datos['desde'] ?? null, $datos['hasta'] ?? null));
    }
}
