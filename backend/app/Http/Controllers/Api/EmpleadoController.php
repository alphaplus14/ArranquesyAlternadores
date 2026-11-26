<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmpleadoRequest;
use App\Http\Requests\MovimientoEmpleadoRequest;
use App\Http\Resources\MovimientoEmpleadoResource;
use App\Models\Empleado;
use App\Models\MovimientoEmpleado;
use App\Services\CajaService;
use App\Services\EmpleadoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RF-27 (S-55 a S-57): cuenta de empleados.
 * - Admin: crea y edita empleados (con apertura al crear), anula movimientos, consulta resumen y
 *   estado de cuenta. No registra préstamos ni abonos.
 * - Mostrador: lista empleados activos con su saldo (S-81, ajusta S-57) y registra préstamos y abonos.
 *   Resumen y estado de cuenta siguen siendo solo del admin.
 * Sin rutas DELETE (regla 8).
 */
class EmpleadoController extends Controller
{
    public function __construct(private readonly EmpleadoService $empleados) {}

    /** Mostrador y admin: empleados activos para elegir, con su saldo vigente (S-81, ajusta S-57). */
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:120']])['q'] ?? ''));

        return response()->json(['data' => $this->empleados->activosConSaldo($q !== '' ? $q : null)->values()]);
    }

    public function resumen(Request $request): JsonResponse
    {
        $datos = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'page' => ['nullable', 'integer', 'min:1'], 'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $r = $this->empleados->resumen(trim((string) ($datos['q'] ?? '')) ?: null, (int) ($datos['por_pagina'] ?? 50)); // S-70
        $p = $r['pagina'];

        return response()->json([
            'data' => collect($p->items())->map(fn ($e) => [
                'id' => $e->id,
                'nombre' => $e->nombre,
                'documento' => $e->documento,
                'activo' => (bool) $e->activo,
                'saldo' => CajaService::dinero((string) $e->saldo),
                'ultimo_movimiento' => $e->ultimo_movimiento,
            ])->all(),
            'meta' => ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()],
            'totales' => ['total_pendiente' => $r['total_pendiente'], 'empleados_con_saldo' => $r['empleados_con_saldo']],
        ]);
    }

    public function store(EmpleadoRequest $request): JsonResponse
    {
        $empleado = $this->empleados->crear($request->validated(), $request->user());

        return response()->json($this->datos($empleado), 201);
    }

    public function update(EmpleadoRequest $request, Empleado $empleado): JsonResponse
    {
        $empleado = $this->empleados->actualizar($empleado, $request->validated());

        return response()->json($this->datos($empleado));
    }

    public function estadoCuenta(Empleado $empleado): JsonResponse
    {
        return response()->json($this->empleados->estadoCuenta($empleado));
    }

    public function prestamo(MovimientoEmpleadoRequest $request, Empleado $empleado): JsonResponse
    {
        $m = $this->empleados->registrarPrestamo($empleado, $request->validated(), $request->user());

        return (new MovimientoEmpleadoResource($m->load('cuenta:id,nombre', 'usuario:id,name')))->response()->setStatusCode(201);
    }

    public function abono(MovimientoEmpleadoRequest $request, Empleado $empleado): JsonResponse
    {
        $m = $this->empleados->registrarAbono($empleado, $request->validated(), $request->user());

        return (new MovimientoEmpleadoResource($m->load('cuenta:id,nombre', 'usuario:id,name')))->response()->setStatusCode(201);
    }

    public function anular(Request $request, MovimientoEmpleado $movimiento): JsonResponse
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:500']], [
            'motivo.required' => 'Escriba el motivo de la anulación.',
            'motivo.min' => 'El motivo debe tener al menos 3 caracteres.',
            'motivo.max' => 'El motivo admite máximo 500 caracteres.',
        ]);

        $this->empleados->anular($movimiento, $datos['motivo'], $request->user());

        return response()->json([
            'movimiento' => new MovimientoEmpleadoResource($movimiento->load('cuenta:id,nombre', 'usuario:id,name', 'anulacion')),
            'saldo' => $this->empleados->saldo($movimiento->empleado_id),
        ], 201);
    }

    private function datos(Empleado $empleado): array
    {
        return $empleado->only('id', 'nombre', 'documento', 'activo') + ['saldo' => $this->empleados->saldo($empleado->id)];
    }
}
