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

    /**
     * Consulta previa al agregar un código al lote: misma regla y mismo mensaje que al registrar.
     * S-34 (salidas): acepta el código del proveedor o del fabricante; referencia_id resuelve la
     * ambigüedad y excluir[] son las unidades ya elegidas en el lote. S-38: cantidad N asigna N
     * unidades distintas; responde {unidades: [...]}.
     */
    public function unidad(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:32'],
            'tipo' => ['required', Rule::enum(TipoAjuste::class)],
            'referencia_id' => ['nullable', 'integer'],
            'excluir' => ['nullable', 'array', 'max:'.AjusteService::MAX_UNIDADES],
            'excluir.*' => ['integer'],
            // S-38: cuántas unidades asignar con un código de proveedor (con una etiqueta, 1).
            'cantidad' => ['nullable', 'integer', 'min:1', 'max:'.AjusteService::MAX_UNIDADES],
        ]);

        $unidades = $this->ajustes->verificar(
            TipoAjuste::from($datos['tipo']),
            $datos['codigo'],
            isset($datos['referencia_id']) ? (int) $datos['referencia_id'] : null,
            array_map('intval', $datos['excluir'] ?? []),
            (int) ($datos['cantidad'] ?? 1),
        );

        return response()->json(['unidades' => UnidadResource::collection($unidades)]);
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
