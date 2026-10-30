<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IngresoItemRequest;
use App\Http\Resources\IngresoResource;
use App\Http\Resources\UnidadResource;
use App\Models\Ingreso;
use App\Models\IngresoItem;
use App\Models\Unidad;
use App\Services\IngresoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * RF-05: ingreso por remisión: crear borrador, agregar ítems y confirmar.
 * RF-09 / RF-10: descuento y líneas sin IVA. RF-03: al confirmar se crean las unidades.
 */
class IngresoController extends Controller
{
    public function __construct(private readonly IngresoService $servicio) {}

    public function index(Request $request): JsonResponse
    {
        $pagina = Ingreso::query()
            ->with(['proveedor:id,nombre', 'usuario:id,name'])
            ->withSum('items as unidades', 'cantidad')
            ->when($request->query('proveedor_id'), fn ($q, $v) => $q->where('proveedor_id', $v))
            ->when($request->query('estado'), fn ($q, $v) => $q->where('estado', $v))
            ->orderByDesc('id')
            ->paginate(25);

        return response()->json($pagina);
    }

    public function store(Request $request): JsonResponse
    {
        $ingreso = $this->servicio->crearBorrador($this->validarEncabezado($request), $request->user());

        return (new IngresoResource($ingreso->load('proveedor:id,nombre', 'items')))->response()->setStatusCode(201);
    }

    public function show(Ingreso $ingreso): IngresoResource
    {
        return new IngresoResource($this->cargar($ingreso));
    }

    public function update(Request $request, Ingreso $ingreso): IngresoResource
    {
        $this->servicio->actualizarEncabezado($ingreso, $this->validarEncabezado($request, parcial: true));

        return new IngresoResource($this->cargar($ingreso));
    }

    public function agregarItem(IngresoItemRequest $request, Ingreso $ingreso): IngresoResource
    {
        $this->servicio->agregarItem($ingreso, $request->validated());

        return new IngresoResource($this->cargar($ingreso));
    }

    public function actualizarItem(IngresoItemRequest $request, Ingreso $ingreso, IngresoItem $item): IngresoResource
    {
        $this->servicio->actualizarItem($ingreso, $item, $request->validated());

        return new IngresoResource($this->cargar($ingreso));
    }

    public function quitarItem(Ingreso $ingreso, IngresoItem $item): IngresoResource
    {
        $this->servicio->quitarItem($ingreso, $item);

        return new IngresoResource($this->cargar($ingreso));
    }

    public function confirmar(Request $request, Ingreso $ingreso): IngresoResource
    {
        $ingreso = $this->servicio->confirmar($ingreso, $request->user());

        return new IngresoResource($this->cargar($ingreso));
    }

    /** RF-03: unidades creadas por el ingreso, para imprimir sus etiquetas. */
    public function unidades(Ingreso $ingreso): AnonymousResourceCollection
    {
        $unidades = Unidad::query()
            ->whereIn('ingreso_item_id', $ingreso->items()->select('id'))
            ->with('referencia:id,codigo_referencia,descripcion,marca_id', 'referencia.marca:id,nombre')
            ->orderBy('id')
            ->get(['id', 'referencia_id', 'codigo_barras', 'estado']);

        return UnidadResource::collection($unidades);
    }

    private function cargar(Ingreso $ingreso): Ingreso
    {
        return $ingreso->load([
            'proveedor:id,nombre',
            'usuario:id,name',
            'items' => fn ($q) => $q->orderBy('id'),
            'items.referencia:id,codigo_referencia,descripcion',
        ]);
    }

    private function validarEncabezado(Request $request, bool $parcial = false): array
    {
        $requerido = $parcial ? 'sometimes' : 'required';

        return $request->validate([
            'proveedor_id' => [$requerido, 'integer', 'exists:proveedores,id'],
            'numero_documento' => [$requerido, 'string', 'max:60'],
            'fecha' => [$requerido, 'date'],
            'descuento_total' => ['sometimes', 'numeric', 'min:0', 'max:999999999999'], // RF-09
        ]);
    }
}
