<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Enums\Rol;
use App\Http\Requests\ProveedorRequest;
use App\Models\Proveedor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RF-08 (filtro de agotados) / RF-11. RF-16: plazo_dias prellena el vencimiento de las compras.
 * S-24: crear es de admin y logística (rutas); editar y borrar, solo del admin.
 * S-29: NIT obligatorio al crear y asesor opcional (ProveedorRequest).
 * S-83: el mostrador no recibe `nit` ni `asesor` (solo filtra por nombre); admin, logística y
 * contable los reciben igual que antes. `show`, `update` y `destroy` son solo del admin (rutas).
 */
class ProveedorController extends Controller
{
    private const CAMPOS = ['id', 'nombre', 'nit', 'asesor', 'plazo_dias'];

    /** S-83: campos que ve el mostrador. */
    private const CAMPOS_MOSTRADOR = ['id', 'nombre', 'plazo_dias'];

    public function index(Request $request): JsonResponse
    {
        $campos = $request->user()->rol === Rol::Mostrador ? self::CAMPOS_MOSTRADOR : self::CAMPOS;

        return response()->json(Proveedor::orderBy('nombre')->get($campos));
    }

    public function store(ProveedorRequest $request): JsonResponse
    {
        $proveedor = Proveedor::create($request->validated());

        return response()->json($proveedor->only(self::CAMPOS), 201);
    }

    public function show(Proveedor $proveedor): JsonResponse
    {
        return response()->json($proveedor->only(self::CAMPOS));
    }

    public function update(ProveedorRequest $request, Proveedor $proveedor): JsonResponse
    {
        $proveedor->update($request->validated());

        return response()->json($proveedor->only(self::CAMPOS));
    }

    public function destroy(Proveedor $proveedor): JsonResponse
    {
        $proveedor->delete();

        return response()->json(null, 204);
    }
}
