<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Proveedor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** RF-08 (filtro de agotados) / RF-11 */
class ProveedorController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Proveedor::orderBy('nombre')->get(['id', 'nombre', 'nit']));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(Proveedor::create($this->validar($request)), 201);
    }

    public function show(Proveedor $proveedor): JsonResponse
    {
        return response()->json($proveedor);
    }

    public function update(Request $request, Proveedor $proveedor): JsonResponse
    {
        $proveedor->update($this->validar($request, $proveedor));

        return response()->json($proveedor);
    }

    public function destroy(Proveedor $proveedor): JsonResponse
    {
        $proveedor->delete();

        return response()->json(null, 204);
    }

    private function validar(Request $request, ?Proveedor $proveedor = null): array
    {
        $requerido = $proveedor ? 'sometimes' : 'required';

        return $request->validate([
            'nombre' => [$requerido, 'string', 'max:150'],
            'nit' => ['nullable', 'string', 'max:30', Rule::unique('proveedores')->ignore($proveedor?->id)],
        ]);
    }
}
