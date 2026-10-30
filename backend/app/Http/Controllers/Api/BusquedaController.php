<?php

namespace App\Http\Controllers\Api;

use App\Enums\EstadoUnidad;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReferenciaResource;
use App\Http\Resources\UnidadResource;
use App\Models\CodigoUniversal;
use App\Models\Referencia;
use App\Models\Unidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RF-12: respaldo cuando el escáner no lee. Busca por código de barras (exacto), por código de
 * referencia o por código universal (prefijo, con índice) y devuelve las unidades disponibles
 * para elegir. Sin LIKE con comodín inicial: la velocidad es requisito.
 */
class BusquedaController extends Controller
{
    private const MAX_REFERENCIAS = 20;

    private const MAX_UNIDADES_POR_REFERENCIA = 20;

    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']])['q']);

        $unidad = Unidad::query()
            ->where('codigo_barras', $q)
            ->with('referencia.marca:id,nombre', 'referencia.proveedor:id,nombre', 'referencia.codigoUniversal:id,codigo')
            ->first();

        $prefijo = addcslashes($q, '%_\\').'%';
        $universales = CodigoUniversal::where('codigo', 'like', $prefijo)->limit(self::MAX_REFERENCIAS)->pluck('id');

        $referencias = Referencia::query()
            ->where(fn ($w) => $w
                ->where('codigo_referencia', 'like', $prefijo)
                ->when($universales->isNotEmpty(), fn ($w) => $w->orWhereIn('codigo_universal_id', $universales)))
            ->with([
                'marca:id,nombre',
                'proveedor:id,nombre',
                'codigoUniversal:id,codigo',
                // Laravel limita por referencia con funciones de ventana (MySQL 8).
                'unidades' => fn ($u) => $u
                    ->where('estado', EstadoUnidad::Disponible)
                    ->orderBy('id')
                    ->limit(self::MAX_UNIDADES_POR_REFERENCIA)
                    ->select('id', 'referencia_id', 'codigo_barras', 'estado'),
            ])
            ->orderBy('codigo_referencia')
            ->limit(self::MAX_REFERENCIAS)
            ->get();

        return response()->json([
            'unidad' => $unidad ? new UnidadResource($unidad) : null,
            'referencias' => ReferenciaResource::collection($referencias),
        ]);
    }
}
