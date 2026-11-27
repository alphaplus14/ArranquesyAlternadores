<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AbonoResource;
use App\Models\AbonoCliente;
use App\Models\Cliente;
use App\Models\CuentaPago;
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
            'valor' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999999999'],
            // RF-24 (S-48): se elige la cuenta; el medio se deriva de su tipo.
            'cuenta_pago_id' => ['required', 'integer', Rule::exists('cuentas_pago', 'id')->where('activa', true)],
            'referencia' => ['nullable', 'string', 'max:60'],
            'nota' => ['nullable', 'string', 'max:255'],
            // S-79: todo abono nuevo va a una venta a crédito cerrada elegida (ya no hay abono general).
            'venta_id' => ['required', 'integer'],
        ], [
            'venta_id.required' => 'Elija la factura o remisión a la que se aplica el abono.',
            'valor.decimal' => 'El valor admite hasta 2 decimales.',
            'valor.gt' => 'El valor del abono debe ser mayor que 0.',
            'cuenta_pago_id.required' => 'Elija la cuenta donde entra el abono.',
            'cuenta_pago_id.exists' => 'Elija una cuenta activa de la lista.',
        ]);

        $abono = $this->abonos->registrar(
            $cliente,
            $datos['fecha'] ?? RangoFechas::hoy(),
            $datos['valor'],
            CuentaPago::findOrFail($datos['cuenta_pago_id']),
            $datos['referencia'] ?? null,
            $datos['nota'] ?? null,
            $request->user(),
            (int) $datos['venta_id'],
        );

        // El saldo nuevo va en la misma respuesta: la pantalla no recarga todo.
        return response()->json([
            'abono' => new AbonoResource($abono->load('usuario:id,name', 'anulacion', 'venta:id,factura_contapyme', 'cuenta:id,nombre,tipo')),
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
