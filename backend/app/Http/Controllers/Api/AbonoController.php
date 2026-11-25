<?php

namespace App\Http\Controllers\Api;

use App\Enums\MedioPago;
use App\Http\Controllers\Controller;
use App\Http\Resources\AbonoResource;
use App\Models\AbonoCliente;
use App\Models\Cliente;
use App\Services\AbonoService;
use App\Services\CarteraService;
use App\Support\RangoFechas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * RF-15 (S-30): registrar abonos (admin y mostrador) y anularlos (solo admin). No hay rutas para
 * editar ni borrar abonos.
 */
class AbonoController extends Controller
{
    public function __construct(
        private readonly AbonoService $abonos,
        private readonly CarteraService $cartera,
    ) {}

    public function store(Request $request, Cliente $cliente): JsonResponse
    {
        $datos = $request->validate([
            // S-36 (antes S-25 de Frank): hoy por defecto; puede ser anterior, nunca futura.
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'valor' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'medio' => ['required', Rule::enum(MedioPago::class)],
            'referencia' => ['nullable', 'string', 'max:60'],
            'nota' => ['nullable', 'string', 'max:255'],
            // S-32: null = abono general (FIFO); con número, dirigido a esa venta.
            'venta_id' => ['nullable', 'integer'],
        ], [
            'valor.gt' => 'El valor del abono debe ser mayor que 0.',
        ]);

        $abono = $this->abonos->registrar(
            $cliente,
            $datos['fecha'] ?? RangoFechas::hoy(),
            $datos['valor'],
            MedioPago::from($datos['medio']),
            $datos['referencia'] ?? null,
            $datos['nota'] ?? null,
            $request->user(),
            isset($datos['venta_id']) ? (int) $datos['venta_id'] : null,
        );

        // El saldo nuevo va en la misma respuesta: la pantalla no recarga todo.
        return response()->json([
            'abono' => new AbonoResource($abono->load('usuario:id,name', 'anulacion', 'venta:id,factura_contapyme')),
            'resumen' => $this->cartera->resumenDe($cliente),
        ], 201);
    }

    public function anular(Request $request, AbonoCliente $abono): JsonResponse
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:500']]);

        $this->abonos->anular($abono, $datos['motivo'], $request->user());

        return response()->json([
            'abono' => new AbonoResource($abono->load('usuario:id,name', 'anulacion.usuario:id,name', 'venta:id,factura_contapyme')),
            'resumen' => $this->cartera->resumenDe($abono->cliente()->firstOrFail()),
        ], 201);
    }
}
