<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CorreccionIngresoRequest;
use App\Http\Requests\IngresoEncabezadoRequest;
use App\Http\Requests\IngresoItemRequest;
use App\Http\Resources\IngresoCorreccionResource;
use App\Http\Resources\IngresoItemResource;
use App\Http\Resources\IngresoResource;
use App\Http\Resources\UnidadResource;
use App\Models\Ingreso;
use App\Models\IngresoCorreccion;
use App\Models\IngresoItem;
use App\Models\Unidad;
use App\Services\IngresoService;
use App\Support\RangoFechas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * RF-05: ingreso por remisión: crear borrador, agregar ítems y confirmar.
 * RF-09 / RF-10: descuento y líneas sin IVA. RF-03: al confirmar se crean las unidades.
 * RF-04: la factura de compra se ingresa a mano, igual que la remisión. RF-20: ítems por escaneo.
 * RF-16: forma de pago, vencimiento digitado y total fijado al confirmar.
 * RF-19: ligar remisiones a una factura en borrador, prellenarla y aceptar diferencias.
 */
class IngresoController extends Controller
{
    public function __construct(private readonly IngresoService $servicio) {}

    public function index(Request $request): JsonResponse
    {
        $pagina = Ingreso::query()
            ->with(['proveedor:id,nombre,asesor', 'usuario:id,name', 'confirmadoPor:id,name']) // S-29: asesor bajo el nombre
            ->withSum('items as unidades', 'cantidad')
            ->withCount('correcciones') // RF-28
            ->when($request->query('proveedor_id'), fn ($q, $v) => $q->where('proveedor_id', $v))
            ->when($request->query('estado'), fn ($q, $v) => $q->where('estado', $v))
            ->when($request->boolean('corregidos'), fn ($q) => $q->has('correcciones'))
            ->orderByDesc('id')
            // S-70: filas por página del usuario (25 por defecto, tope 100).
            ->paginate(min(max((int) $request->query('por_pagina', 25), 1), 100));
        $pagina->getCollection()->each(fn (Ingreso $i) => $i->setAttribute('corregido', $i->correcciones_count > 0));

        return response()->json($pagina);
    }

    /** RF-04 / RF-05: 201 si crea el borrador; 200 si el documento ya estaba en borrador y se reabre. */
    public function store(IngresoEncabezadoRequest $request): JsonResponse
    {
        $ingreso = $this->servicio->crearBorrador($request->validated(), $request->user());

        return (new IngresoResource($this->cargar($ingreso)))
            ->response()
            ->setStatusCode($ingreso->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Ingreso $ingreso): IngresoResource
    {
        return new IngresoResource($this->cargar($ingreso));
    }

    public function update(IngresoEncabezadoRequest $request, Ingreso $ingreso): IngresoResource
    {
        $ingreso = $this->servicio->actualizarEncabezado($ingreso, $request->validated());

        return new IngresoResource($this->cargar($ingreso));
    }

    /** RF-20: 201 si crea la línea, 200 si sumó 1 a la existente; 404 con motivo si el código no existe. */
    public function escanear(Request $request, Ingreso $ingreso): JsonResponse
    {
        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:120'],
            // S-25: para resolver un "Código ambiguo", la referencia elegida entre las opciones.
            'referencia_id' => ['sometimes', 'nullable', 'integer'],
        ]);
        $item = $this->servicio->escanear($ingreso, $datos['codigo'], $datos['referencia_id'] ?? null);

        return (new IngresoItemResource($item))->response()->setStatusCode($item->wasRecentlyCreated ? 201 : 200);
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

    /** RF-19: aceptar_diferencia y motivo solo hacen falta si la factura cubre menos de lo recibido. */
    public function confirmar(Request $request, Ingreso $ingreso): IngresoResource
    {
        $datos = $request->validate([
            'aceptar_diferencia' => ['sometimes', 'boolean'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ]);

        $ingreso = $this->servicio->confirmar(
            $ingreso,
            $request->user(),
            (bool) ($datos['aceptar_diferencia'] ?? false),
            $datos['motivo'] ?? null,
        );

        return new IngresoResource($this->cargar($ingreso));
    }

    /** RF-19: remisiones confirmadas del proveedor de la factura que aún no tienen factura. */
    public function remisionesDisponibles(Ingreso $ingreso): JsonResponse
    {
        $remisiones = $this->servicio->remisionesDisponibles($ingreso)->map(fn (Ingreso $r) => [
            'id' => $r->id,
            'numero_documento' => $r->numero_documento,
            'fecha' => $r->fecha->toDateString(),
            'forma_pago' => $r->forma_pago,
            'total' => $r->total,
            'unidades' => (int) $r->unidades,
        ]);

        return response()->json(['data' => $remisiones]);
    }

    public function ligarRemisiones(Request $request, Ingreso $ingreso): IngresoResource
    {
        $datos = $request->validate([
            'remision_ids' => ['required', 'array', 'min:1'],
            'remision_ids.*' => ['integer', 'distinct'],
        ]);

        return new IngresoResource($this->cargar($this->servicio->ligarRemisiones($ingreso, $datos['remision_ids'])));
    }

    public function desligarRemision(Ingreso $ingreso, Ingreso $remision): IngresoResource
    {
        return new IngresoResource($this->cargar($this->servicio->desligarRemision($ingreso, $remision)));
    }

    public function prellenar(Ingreso $ingreso): IngresoResource
    {
        return new IngresoResource($this->cargar($this->servicio->prellenar($ingreso)));
    }

    /** RF-28: vista previa de una corrección, sin escribir nada. */
    public function vistaPreviaCorreccion(CorreccionIngresoRequest $request, Ingreso $ingreso): JsonResponse
    {
        return response()->json($this->servicio->vistaPreviaCorreccion($ingreso, $request->datosCorreccion()));
    }

    /** RF-28 (S-58): corrige un ingreso confirmado. Devuelve la corrección y el ingreso al día. */
    public function corregir(CorreccionIngresoRequest $request, Ingreso $ingreso): JsonResponse
    {
        $correccion = $this->servicio->corregir($ingreso, $request->datosCorreccion(), $request->validated('motivo'), $request->user());

        return response()->json([
            'correccion' => new IngresoCorreccionResource($correccion->load('usuario:id,name')),
            'ingreso' => new IngresoResource($this->cargar($ingreso->refresh())),
        ], 201);
    }

    /** RF-28: lista de correcciones (solo admin, solo consulta), por fecha, proveedor y usuario. */
    public function correcciones(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'proveedor_id' => ['nullable', 'integer'],
            'usuario_id' => ['nullable', 'integer'],
            'ingreso_id' => ['nullable', 'integer'],
        ]);

        $pagina = IngresoCorreccion::query()
            ->with('usuario:id,name', 'ingreso:id,tipo,numero_documento,proveedor_id', 'ingreso.proveedor:id,nombre')
            ->when($f['desde'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', RangoFechas::inicio($v)))
            ->when($f['hasta'] ?? null, fn ($q, $v) => $q->where('created_at', '<', RangoFechas::finExclusivo($v)))
            ->when($f['proveedor_id'] ?? null, fn ($q, $v) => $q->whereIn('ingreso_id', Ingreso::query()->where('proveedor_id', $v)->select('id')))
            ->when($f['usuario_id'] ?? null, fn ($q, $v) => $q->where('usuario_id', $v))
            ->when($f['ingreso_id'] ?? null, fn ($q, $v) => $q->where('ingreso_id', $v))
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->query('por_pagina', 25), 1), 100)); // S-70

        return IngresoCorreccionResource::collection($pagina);
    }

    /** RF-03: unidades creadas por el ingreso, para imprimir sus etiquetas. */
    public function unidades(Ingreso $ingreso): AnonymousResourceCollection
    {
        $unidades = Unidad::query()
            ->whereIn('ingreso_item_id', $ingreso->items()->select('id'))
            ->with('referencia:id,codigo_referencia,calidad,descripcion,proveedor_id', 'referencia.proveedor:id,nombre')
            ->orderBy('id')
            ->get(['id', 'referencia_id', 'codigo_barras', 'estado', 'etiqueta_impresa_at']);
        // RF-28: la pantalla de Etiquetas preselecciona las pendientes (sin etiqueta impresa).
        $unidades->each(fn (Unidad $u) => $u->setAttribute('etiqueta_impresa', $u->etiqueta_impresa_at !== null));

        return UnidadResource::collection($unidades);
    }

    private function cargar(Ingreso $ingreso): Ingreso
    {
        return $ingreso->load([
            'proveedor:id,nombre,nit,asesor,plazo_dias', // S-29: asesor en el encabezado
            'usuario:id,name',
            'confirmadoPor:id,name',
            'items' => fn ($q) => $q->orderBy('id'),
            'items.referencia:id,codigo_referencia,descripcion',
            'factura:id,numero_documento,estado', // RF-19
            'remisiones' => fn ($q) => $q->orderBy('fecha')->orderBy('id')->withSum('items as unidades', 'cantidad'),
            'correcciones' => fn ($q) => $q->orderByDesc('id')->with('usuario:id,name'), // RF-28
        ]);
    }
}
