<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Marca;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Supuesto S-03: las marcas son un catálogo editable. RF-11. */
class MarcaController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Marca::orderBy('nombre')->get(['id', 'nombre']));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(Marca::create($this->validar($request)), 201);
    }

    public function show(Marca $marca): JsonResponse
    {
        return response()->json($marca);
    }

    public function update(Request $request, Marca $marca): JsonResponse
    {
        $marca->update($this->validar($request, $marca));

        return response()->json($marca);
    }

    public function destroy(Marca $marca): JsonResponse
    {
        $marca->delete();

        return response()->json(null, 204);
    }

    private function validar(Request $request, ?Marca $marca = null): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:80', Rule::unique('marcas')->ignore($marca?->id)],
        ]);
    }
}
