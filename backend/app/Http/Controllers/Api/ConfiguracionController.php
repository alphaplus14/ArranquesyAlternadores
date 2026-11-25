<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CajaService;
use App\Services\PanelService;
use App\Services\StockMinimoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RF-22 (S-28): mínimo general del almacén. S-45 e: días sin movimiento del panel. RF-25: meta de
 * venta diaria y saldo inicial de caja. Solo admin
 * (ruta); cada cambio queda auditado (RF-11).
 */
class ConfiguracionController extends Controller
{
    public function __construct(
        private readonly StockMinimoService $stockMinimo,
        private readonly PanelService $panel,
        private readonly CajaService $caja,
    ) {}

    /** RF-25 (S-51): meta de venta diaria en pesos. */
    public function metaVenta(): JsonResponse
    {
        return response()->json(['valor' => $this->caja->metaVentaDiaria()]);
    }

    public function fijarMetaVenta(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'valor' => ['required', 'integer', 'min:0', 'max:999999999999'],
        ], [
            'valor.required' => 'Escriba la meta de venta diaria.',
            'valor.integer' => 'La meta va en pesos enteros.',
            'valor.min' => 'La meta no puede ser negativa.',
        ]);

        return response()->json(['valor' => $this->caja->fijarMetaVentaDiaria((int) $datos['valor'])]);
    }

    /** RF-25 (S-49): saldo inicial de caja (null mientras no se fije). */
    public function saldoCajaInicial(): JsonResponse
    {
        return response()->json(['saldo' => $this->caja->saldoInicial()]);
    }

    /** S-49: se fija una sola vez; después responde 422. */
    public function fijarSaldoCajaInicial(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'valor' => ['required', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,2'],
            'fecha' => ['required', 'date_format:Y-m-d'],
        ], [
            'valor.required' => 'Escriba el saldo inicial de caja.',
            'valor.min' => 'El saldo inicial no puede ser negativo.',
            'fecha.required' => 'Indique la fecha del saldo inicial.',
        ]);

        return response()->json(['saldo' => $this->caja->fijarSaldoInicial((string) $datos['valor'], $datos['fecha'])]);
    }

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
