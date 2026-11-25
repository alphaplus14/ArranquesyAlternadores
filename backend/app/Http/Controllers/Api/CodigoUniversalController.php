<?php

namespace App\Http\Controllers\Api;

use App\Enums\Rol;
use App\Exceptions\ReglaInventarioException;
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
            // S-59: el selector de una referencia pide solo los universales de su categoría.
            ->when($request->integer('categoria_id'), fn ($query, $id) => $query->where('categoria_id', $id))
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

        // S-59 / S-28: logística crea y edita universales, pero el mínimo del universal sigue siendo
        // solo del administrador (RF-22).
        if ($request->has('stock_minimo') && $request->user()?->rol !== Rol::Admin) {
            throw new ReglaInventarioException('El stock mínimo del código universal lo cambia solo el administrador.', 403);
        }

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

        // RF-01 / S-59: un universal no puede quedar con referencias de otra categoría.
        if ($universal && isset($datos['categoria_id']) && (int) $datos['categoria_id'] !== (int) $universal->categoria_id
            && Referencia::query()->where('codigo_universal_id', $universal->id)->where('categoria_id', '!=', $datos['categoria_id'])->exists()) {
            throw ValidationException::withMessages(['categoria_id' => 'Este código universal tiene referencias de otra categoría: cámbielas o quíteselas antes de cambiar la categoría.']);
        }

        return $datos;
    }
}
