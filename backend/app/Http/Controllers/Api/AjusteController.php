<?php

namespace App\Http\Controllers\Api;

use App\Enums\MotivoAjuste;
use App\Enums\TipoAjuste;
use App\Http\Controllers\Controller;
use App\Http\Requests\AjusteRequest;
use App\Http\Resources\AjusteResource;
use App\Http\Resources\UnidadResource;
use App\Models\AjusteInventario;
use App\Services\AjusteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * RF-21 (S-27): salidas (bajas) y reintegros de unidades por logística y admin. Sin rutas para
 * editar ni borrar: los ajustes son inmutables. El historial solo se consulta en pantalla.
 */
class AjusteController extends Controller
{
    public function __construct(private readonly AjusteService $ajustes) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filtros = $request->validate([
            'tipo' => ['nullable', Rule::enum(TipoAjuste::class)],
            'motivo' => ['nullable', Rule::enum(MotivoAjuste::class)],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'usuario_id' => ['nullable', 'integer'],
        ]);

        return AjusteResource::collection($this->ajustes->historial($filtros));
    }

    /** Consulta previa al agregar un código al lote: misma regla y mismo mensaje que al registrar. */
    public function unidad(Request $request): UnidadResource
    {
        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:32'],
            'tipo' => ['required', Rule::enum(TipoAjuste::class)],
        ]);

        return new UnidadResource($this->ajustes->verificar(TipoAjuste::from($datos['tipo']), $datos['codigo']));
    }

    public function salidas(AjusteRequest $request): JsonResponse
    {
        $ajuste = $this->ajustes->retirar($request->motivo(), $request->validated('nota'), $request->validated('codigos'), $request->user());

        return $this->creado($ajuste);
    }

    public function reintegros(AjusteRequest $request): JsonResponse
    {
        $ajuste = $this->ajustes->reintegrar($request->motivo(), $request->validated('nota'), $request->validated('codigos'), $request->user());

        return $this->creado($ajuste);
    }

    private function creado(AjusteInventario $ajuste): JsonResponse
    {
        $ajuste->load([
            'usuario:id,name',
            'items' => fn ($q) => $q->orderBy('id'),
            'items.unidad:id,referencia_id,codigo_barras,estado',
            'items.unidad.referencia:id,codigo_referencia,descripcion,calidad,proveedor_id',
            'items.unidad.referencia.proveedor:id,nombre',
        ]);

        return (new AjusteResource($ajuste))->response()->setStatusCode(201);
    }
}
