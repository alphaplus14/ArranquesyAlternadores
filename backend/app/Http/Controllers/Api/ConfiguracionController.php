<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StockMinimoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** RF-22 (S-28): mínimo general del almacén. Solo admin (ruta); cada cambio queda auditado (RF-11). */
class ConfiguracionController extends Controller
{
    public function __construct(private readonly StockMinimoService $stockMinimo) {}

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
