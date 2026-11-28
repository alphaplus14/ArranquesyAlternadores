<?php

namespace App\Http\Controllers\Api;

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
        return response()->json([
            'data' => $this->propietarios->listar($request->user()->rol, $request->boolean('incluir_inactivas')),
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
