<?php

namespace App\Http\Controllers\Api;

use App\Enums\MetodoCaptura;
use App\Enums\TipoMovimiento;
use App\Http\Controllers\Controller;
use App\Http\Resources\MovimientoInventarioResource;
use App\Services\MovimientoInventarioService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * RF-21 (S-87): historial de movimientos de inventario por producto. Solo lectura; el movimiento
 * es inmutable y no hay rutas de escritura.
 */
class MovimientoInventarioController extends Controller
{
    public function __construct(private readonly MovimientoInventarioService $movimientos) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filtros = $request->validate([
            'referencia_id' => ['nullable', 'integer'],
            'universal_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'min:2', 'max:60'],
            'tipo' => ['nullable', Rule::enum(TipoMovimiento::class)],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'proveedor_id' => ['nullable', 'integer'],
            'usuario_id' => ['nullable', 'integer'],
            'metodo_captura' => ['nullable', Rule::enum(MetodoCaptura::class)],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'], // S-70
        ]);

        return MovimientoInventarioResource::collection($this->movimientos->historial($filtros));
    }
}
