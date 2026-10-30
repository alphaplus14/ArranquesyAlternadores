<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** RF-01 / RF-11. Catálogo pequeño: se devuelve completo, sin paginar. */
class CategoriaController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Categoria::orderBy('nombre')->get());
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(Categoria::create($this->validar($request)), 201);
    }

    public function show(Categoria $categoria): JsonResponse
    {
        return response()->json($categoria);
    }

    public function update(Request $request, Categoria $categoria): JsonResponse
    {
        $categoria->update($this->validar($request, $categoria));

        return response()->json($categoria);
    }

    public function destroy(Categoria $categoria): JsonResponse
    {
        $categoria->delete();

        return response()->json(null, 204);
    }

    private function validar(Request $request, ?Categoria $categoria = null): array
    {
        $requerido = $categoria ? 'sometimes' : 'required';

        return $request->validate([
            'nombre' => [$requerido, 'string', 'max:80', Rule::unique('categorias')->ignore($categoria?->id)],
            'admite_codigo_universal' => ['sometimes', 'boolean'],
        ]);
    }
}
