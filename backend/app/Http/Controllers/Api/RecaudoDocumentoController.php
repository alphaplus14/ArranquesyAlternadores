<?php

namespace App\Http\Controllers\Api;

use App\Enums\TipoDocumentoVenta;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecaudoDocumentoRequest;
use App\Http\Resources\RecaudoDocumentoResource;
use App\Models\RecaudoDocumento;
use App\Services\RecaudoDocumentoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * RF-25 / RF-26 (S-76): recaudo de documentos de Contapyme en la caja del día. Registrar y anular:
 * solo mostrador. Consulta por rango: contable (y admin). Solo en pantalla, sin exportar.
 */
class RecaudoDocumentoController extends Controller
{
    public function __construct(private readonly RecaudoDocumentoService $recaudos) {}

    public function store(RecaudoDocumentoRequest $request, string $fecha): JsonResponse
    {
        $recaudo = $this->recaudos->registrar($fecha, $request->validated(), $request->user());

        return (new RecaudoDocumentoResource($recaudo->load('cuenta:id,nombre,tipo', 'usuario:id,name', 'anulacion')))
            ->response()->setStatusCode(201);
    }

    public function anular(Request $request, RecaudoDocumento $recaudo): JsonResponse
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:500']], [
            'motivo.required' => 'Escriba el motivo de la anulación.',
            'motivo.min' => 'El motivo debe tener al menos 3 caracteres.',
        ]);

        $this->recaudos->anular($recaudo, $datos['motivo'], $request->user());

        return (new RecaudoDocumentoResource($recaudo->load('cuenta:id,nombre,tipo', 'usuario:id,name', 'anulacion.usuario:id,name')))
            ->response()->setStatusCode(201);
    }

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'tipo' => ['nullable', Rule::enum(TipoDocumentoVenta::class)],
            'cuenta_pago_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:60'],
            'page' => ['nullable', 'integer', 'min:1'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $r = $this->recaudos->consultar($filtros, (int) ($filtros['por_pagina'] ?? 50), (int) ($filtros['page'] ?? 1));

        return RecaudoDocumentoResource::collection($r['pagina'])
            ->additional(['totales' => $r['totales']])
            ->response();
    }
}
