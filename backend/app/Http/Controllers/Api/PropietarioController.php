<?php

namespace App\Http\Controllers\Api;

use App\Enums\Rol;
use App\Http\Controllers\Controller;
use App\Models\Propietario;
use App\Services\PropietarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * S-88 (RF-24): dueños de billeteras. GET para admin, mostrador y contable (el select de cuentas).
 * Crear, renombrar y desactivar: solo admin. No hay DELETE.
 */
class PropietarioController extends Controller
{
    public function __construct(private readonly PropietarioService $propietarios) {}

    public function index(Request $request): JsonResponse
    {
        // S-88: el mostrador no ve los dueños (lista vacía; el select de cuentas no los necesita).
        if ($request->user()->rol === Rol::Mostrador) {
            return response()->json(['data' => []]);
        }
        $todas = $request->boolean('incluir_inactivas') && $request->user()->rol === Rol::Admin;

        return response()->json([
            'data' => Propietario::query()
                ->when(! $todas, fn ($q) => $q->where('activo', true))
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'activo']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $this->validar($request);

        return response()->json($this->propietarios->crear($datos)->only('id', 'nombre', 'activo'), 201);
    }

    public function update(Request $request, Propietario $propietario): JsonResponse
    {
        $datos = $this->validar($request, $propietario);

        return response()->json($this->propietarios->actualizar($propietario, $datos)->only('id', 'nombre', 'activo'));
    }

    private function validar(Request $request, ?Propietario $propietario = null): array
    {
        $parcial = $propietario !== null ? 'sometimes' : 'required';
        $datos = $request->validate([
            'nombre' => [$parcial, 'string', 'min:2', 'max:60', Rule::unique('propietarios', 'nombre')->ignore($propietario?->id)],
            'activo' => ['sometimes', 'boolean'],
        ], [
            'nombre.required' => 'Escriba el nombre del dueño.',
            'nombre.unique' => 'Ya existe un dueño con ese nombre.',
        ]);
        if (isset($datos['nombre'])) {
            $datos['nombre'] = trim($datos['nombre']);
        }

        return $datos;
    }
}
