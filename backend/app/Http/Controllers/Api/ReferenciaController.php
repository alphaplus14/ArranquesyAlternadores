<?php

namespace App\Http\Controllers\Api;

use App\Enums\Rol;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReferenciaRequest;
use App\Http\Resources\ReferenciaResource;
use App\Models\Referencia;
use App\Services\CostoCompraService;
use App\Services\ReferenciaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** RF-01 / RF-11. Catálogo con búsqueda y paginación en servidor. */
class ReferenciaController extends Controller
{
    /**
     * RF-22: el universal llega con su mínimo y la suma de sus referencias activas, para que el
     * listado diga si está bajo mínimo sin una consulta por fila.
     */
    private static function relaciones(): array
    {
        return [
            'proveedor:id,nombre',
            'categoria:id,nombre,admite_codigo_universal',
            'codigoUniversal' => fn ($q) => $q->select('id', 'codigo', 'stock_minimo')
                ->withSum(['referencias as existencia_total' => fn ($r) => $r->where('activo', true)], 'existencia'),
        ];
    }

    public function index(Request $request, CostoCompraService $costos): AnonymousResourceCollection
    {
        $q = trim((string) $request->query('q'));

        // S-26: por defecto solo activas; inactivas=1 (admin y logística) INCLUYE también las inactivas.
        $conInactivas = $request->boolean('inactivas') && in_array($request->user()?->rol, [Rol::Admin, Rol::Logistica], true);

        $pagina = Referencia::query()
            ->with(self::relaciones())
            ->unless($conInactivas, fn ($query) => $query->activas())
            ->when($request->query('categoria_id'), fn ($query, $v) => $query->where('categoria_id', $v))
            ->when($request->query('proveedor_id'), fn ($query, $v) => $query->where('proveedor_id', $v))
            ->when($request->query('codigo_universal_id'), fn ($query, $v) => $query->where('codigo_universal_id', $v))
            // Con q: prefijo del código o todas las palabras de la descripción, por relevancia (RF-12).
            ->when($q !== '', fn ($query) => $query->buscarTexto($q), fn ($query) => $query->orderBy('codigo_referencia'))
            ->paginate(min((int) $request->query('por_pagina', 25), 100));

        // S-71: último costo de compra, en lote para la página, solo con `ver-ultimo-costo`.
        if ($request->user()?->can('ver-ultimo-costo')) {
            $costos->adjuntarUltimoCosto($pagina->getCollection(), conDocumento: $request->user()->can('ver-historial-costos'));
        }

        return ReferenciaResource::collection($pagina);
    }

    /**
     * S-25: referencias de OTROS proveedores cuyo código (referencia o código de barras) es
     * exactamente el dado. Solo informa (aviso no bloqueante al crear); máximo 10.
     */
    public function coincidencias(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:120'],
            'proveedor_id' => ['nullable', 'integer'],
        ]);
        $codigo = trim($datos['codigo']);

        $referencias = Referencia::query()
            ->activas()
            ->where(fn ($q) => $q->where('codigo_referencia', $codigo)->orWhere('codigo_fabricante', $codigo))
            ->when($datos['proveedor_id'] ?? null, fn ($q, $id) => $q->where('proveedor_id', '!=', $id))
            ->with('proveedor:id,nombre')
            ->orderBy('id')
            ->limit(10)
            ->get(['id', 'codigo_referencia', 'codigo_fabricante', 'descripcion', 'proveedor_id', 'calidad', 'existencia']);

        return response()->json($referencias->map(fn (Referencia $r) => [
            'id' => $r->id,
            'codigo_referencia' => $r->codigo_referencia,
            'codigo_fabricante' => $r->codigo_fabricante,
            'descripcion' => $r->descripcion,
            'proveedor' => ['id' => $r->proveedor->id, 'nombre' => $r->proveedor->nombre],
            'calidad' => $r->calidad,
            'existencia' => $r->existencia,
        ]));
    }

    public function store(ReferenciaRequest $request): JsonResponse
    {
        $referencia = Referencia::create($request->validated());

        return (new ReferenciaResource($referencia->load(self::relaciones())))->response()->setStatusCode(201);
    }

    public function show(Referencia $referencia): ReferenciaResource
    {
        return new ReferenciaResource($referencia->load(self::relaciones()));
    }

    public function update(ReferenciaRequest $request, Referencia $referencia): ReferenciaResource
    {
        $referencia->update($request->validated());

        return new ReferenciaResource($referencia->load(self::relaciones()));
    }

    /** S-26: sin unidades disponibles. No hay DELETE: una referencia tiene historial. */
    public function desactivar(Referencia $referencia, ReferenciaService $servicio): ReferenciaResource
    {
        return new ReferenciaResource($servicio->desactivar($referencia)->load(self::relaciones()));
    }

    public function reactivar(Referencia $referencia, ReferenciaService $servicio): ReferenciaResource
    {
        return new ReferenciaResource($servicio->reactivar($referencia)->load(self::relaciones()));
    }
}
