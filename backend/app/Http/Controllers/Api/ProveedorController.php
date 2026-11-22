<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProveedorRequest;
use App\Models\Proveedor;
use Illuminate\Http\JsonResponse;

/**
 * RF-08 (filtro de agotados) / RF-11. RF-16: plazo_dias prellena el vencimiento de las compras.
 * S-24: crear es de admin y logística (rutas); editar y borrar, solo del admin.
 */
class ProveedorController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Proveedor::orderBy('nombre')->get(['id', 'nombre', 'nit', 'plazo_dias']));
    }

    public function store(ProveedorRequest $request): JsonResponse
    {
        $proveedor = Proveedor::create($request->validated());

        return response()->json($proveedor->only('id', 'nombre', 'nit', 'plazo_dias'), 201);
    }

    public function show(Proveedor $proveedor): JsonResponse
    {
        return response()->json($proveedor);
    }

    public function update(ProveedorRequest $request, Proveedor $proveedor): JsonResponse
    {
        $proveedor->update($request->validated());

        return response()->json($proveedor);
    }

    public function destroy(Proveedor $proveedor): JsonResponse
    {
        $proveedor->delete();

        return response()->json(null, 204);
    }
}
