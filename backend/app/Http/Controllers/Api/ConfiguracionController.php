<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PanelService;
use App\Services\StockMinimoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RF-22 (S-28): mínimo general del almacén. S-45 e: días sin movimiento del panel. Solo admin
 * (ruta); cada cambio queda auditado (RF-11).
 */
class ConfiguracionController extends Controller
{
    public function __construct(
        private readonly StockMinimoService $stockMinimo,
        private readonly PanelService $panel,
    ) {}

    public function diasSinMovimiento(): JsonResponse
    {
        return response()->json(['valor' => $this->panel->diasSinMovimiento()]);
    }

    public function fijarDiasSinMovimiento(Request $request): JsonResponse
    {
        $min = (int) config('panel.sin_movimiento.dias_minimo');
        $max = (int) config('panel.sin_movimiento.dias_maximo');
        $datos = $request->validate([
            'valor' => ['required', 'integer', "between:{$min},{$max}"],
        ], [
            'valor.required' => 'Escriba los días sin movimiento.',
            'valor.integer' => 'Los días sin movimiento deben ser un número entero.',
            'valor.between' => "Los días sin movimiento van de {$min} a {$max}.",
        ]);

        return response()->json(['valor' => $this->panel->fijarDiasSinMovimiento((int) $datos['valor'])]);
    }

    public function stockMinimo(): JsonResponse
    {
        return response()->json(['valor' => $this->stockMinimo->valorGeneral()]);
    }

    public function fijarStockMinimo(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'valor' => ['present', 'nullable', 'integer', 'min:0', 'max:'.StockMinimoService::MAXIMO],
        ], [
            'valor.integer' => 'El stock mínimo debe ser un número entero.',
            'valor.min' => 'El stock mínimo no puede ser negativo.',
            'valor.max' => 'El stock mínimo no puede pasar de '.StockMinimoService::MAXIMO.'.',
        ]);

        $valor = $datos['valor'] === null ? null : (int) $datos['valor'];

        return response()->json(['valor' => $this->stockMinimo->fijarGeneral($valor)]);
    }
}
