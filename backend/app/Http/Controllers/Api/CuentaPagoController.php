<?php

namespace App\Http\Controllers\Api;

use App\Enums\Rol;
use App\Enums\TipoCuentaPago;
use App\Http\Controllers\Controller;
use App\Models\CuentaPago;
use App\Services\CuentaPagoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * RF-24 (S-48): cuentas de pago. GET para admin y mostrador (solo activas; el admin puede pedir
 * también las inactivas para gestionarlas). Crear, editar y desactivar: solo admin. No hay DELETE.
 * Cada cambio queda en la auditoría (Auditable, RF-11).
 */
class CuentaPagoController extends Controller
{
    public function __construct(private readonly CuentaPagoService $cuentas) {}

    public function index(Request $request): JsonResponse
    {
        $todas = $request->boolean('incluir_inactivas') && $request->user()->rol === Rol::Admin;

        return response()->json([
            'data' => CuentaPago::query()
                ->when(! $todas, fn ($q) => $q->where('activa', true))
                ->orderByRaw("FIELD(tipo, 'efectivo', 'banco', 'datafono', 'billetera')")
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'tipo', 'activa']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $this->validar($request);

        return response()->json($this->cuentas->crear($datos)->only('id', 'nombre', 'tipo', 'activa'), 201);
    }

    public function update(Request $request, CuentaPago $cuenta): JsonResponse
    {
        $datos = $this->validar($request, $cuenta);

        return response()->json($this->cuentas->actualizar($cuenta, $datos)->only('id', 'nombre', 'tipo', 'activa'));
    }

    private function validar(Request $request, ?CuentaPago $cuenta = null): array
    {
        $parcial = $cuenta !== null ? 'sometimes' : 'required';
        $datos = $request->validate([
            'nombre' => [$parcial, 'string', 'min:2', 'max:60', Rule::unique('cuentas_pago', 'nombre')->ignore($cuenta?->id)],
            'tipo' => [$parcial, Rule::in(TipoCuentaPago::valores())],
            'activa' => ['sometimes', 'boolean'],
        ], [
            'nombre.required' => 'Escriba el nombre de la cuenta.',
            'nombre.unique' => 'Ya existe una cuenta con ese nombre.',
            'tipo.in' => 'Elija el tipo: efectivo, banco, datáfono o billetera.',
        ]);
        if (isset($datos['nombre'])) {
            $datos['nombre'] = trim($datos['nombre']);
        }

        return $datos;
    }
}
