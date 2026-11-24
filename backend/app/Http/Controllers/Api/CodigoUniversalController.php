<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\CodigoUniversal;
use App\Models\Referencia;
use App\Services\StockMinimoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** RF-01 / RF-02 / RF-11 */
class CodigoUniversalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));

        $pagina = CodigoUniversal::query()
            ->conExistencia()
            ->with('categoria:id,nombre')
            ->when($q !== '', fn ($query) => $query->where('codigo', 'like', addcslashes($q, '%_\\').'%'))
            ->orderBy('codigo')
            ->paginate(min((int) $request->query('por_pagina', 25), 100));

        return response()->json($pagina);
    }

    public function store(Request $request): JsonResponse
    {
        $universal = CodigoUniversal::create($this->validar($request));

        return response()->json($universal, 201);
    }

    /**
     * RF-02 / S-23: total del universal = suma de sus referencias, con el desglose por referencia
     * (código, proveedor, calidad y existencia), ordenado por código.
     */
    public function show(CodigoUniversal $codigoUniversal): JsonResponse
    {
        $referencias = Referencia::query()
            ->where('codigo_universal_id', $codigoUniversal->id)
            ->with('proveedor:id,nombre')
            ->orderBy('codigo_referencia')
            ->get(['id', 'codigo_referencia', 'calidad', 'descripcion', 'proveedor_id', 'existencia', 'updated_at']);

        return response()->json([
            'id' => $codigoUniversal->id,
            'codigo' => $codigoUniversal->codigo,
            'descripcion' => $codigoUniversal->descripcion,
            'stock_minimo' => $codigoUniversal->stock_minimo, // RF-22
            'categoria' => $codigoUniversal->categoria()->first(['id', 'nombre']),
            'existencia_total' => $referencias->sum('existencia'),
            'referencias' => $referencias->map(fn (Referencia $r) => [
                'id' => $r->id,
                'codigo_referencia' => $r->codigo_referencia,
                'descripcion' => $r->descripcion,
                'proveedor' => ['id' => $r->proveedor->id, 'nombre' => $r->proveedor->nombre],
                'calidad' => $r->calidad,
                'existencia' => $r->existencia,
                'updated_at' => $r->updated_at,
            ]),
        ]);
    }

    public function update(Request $request, CodigoUniversal $codigoUniversal): JsonResponse
    {
        $codigoUniversal->update($this->validar($request, $codigoUniversal));

        return response()->json($codigoUniversal);
    }

    public function destroy(CodigoUniversal $codigoUniversal): JsonResponse
    {
        $codigoUniversal->delete();

        return response()->json(null, 204);
    }

    private function validar(Request $request, ?CodigoUniversal $universal = null): array
    {
        $requerido = $universal ? 'sometimes' : 'required';

        $datos = $request->validate([
            'codigo' => [$requerido, 'string', 'max:60', Rule::unique('codigos_universales')->ignore($universal?->id)],
            'categoria_id' => [$requerido, 'integer', 'exists:categorias,id'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            // RF-22: se evalúa sobre la suma de sus referencias activas. null = general; 0 = sin mínimo.
            'stock_minimo' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.StockMinimoService::MAXIMO],
        ]);

        // RF-01: solo categorías que admiten código universal.
        if (isset($datos['categoria_id']) && ! Categoria::whereKey($datos['categoria_id'])->value('admite_codigo_universal')) {
            throw ValidationException::withMessages(['categoria_id' => 'Esta categoría no admite código universal (RF-01).']);
        }

        return $datos;
    }
}
