<?php

namespace App\Http\Controllers\Api;

use App\Enums\MetodoCaptura;
use App\Enums\TipoReverso;
use App\Http\Controllers\Controller;
use App\Http\Resources\VentaResource;
use App\Models\Venta;
use App\Services\ReversoService;
use App\Services\VentaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * RF-06: venta de mostrador con descargue inmediato por escaneo. RF-12: escaneado o digitado.
 * RF-07: reversos. No hay rutas para editar ni borrar ventas.
 */
class VentaController extends Controller
{
    public function __construct(
        private readonly VentaService $ventas,
        private readonly ReversoService $reversos,
    ) {}

    /** Ventas recientes; por defecto, las abiertas del usuario para retomarlas. */
    public function index(Request $request): JsonResponse
    {
        $pagina = Venta::query()
            ->with('usuario:id,name')
            ->withCount('items')
            ->when($request->query('estado'), fn ($q, $v) => $q->where('estado', $v))
            ->when(! $request->boolean('todas'), fn ($q) => $q->where('usuario_id', $request->user()->id))
            ->orderByDesc('id')
            ->paginate(25);

        return response()->json($pagina);
    }

    public function store(Request $request): JsonResponse
    {
        $venta = $this->ventas->abrir($request->user());

        return (new VentaResource($this->cargar($venta)))->response()->setStatusCode(201);
    }

    public function show(Venta $venta): VentaResource
    {
        return new VentaResource($this->cargar($venta));
    }

    public function escanear(Request $request, Venta $venta): JsonResponse
    {
        $datos = $request->validate([
            'codigo_barras' => ['required', 'string', 'max:32'],
            'metodo_captura' => ['required', Rule::enum(MetodoCaptura::class)],
        ]);

        $item = $this->ventas->escanear(
            $venta,
            $datos['codigo_barras'],
            MetodoCaptura::from($datos['metodo_captura']),
            $request->user(),
        );

        // Respuesta mínima para que el escaneo sea rápido; el frontend refresca la venta aparte.
        $item->unidad->load('referencia:id,codigo_referencia,descripcion,existencia,marca_id,codigo_universal_id', 'referencia.marca:id,nombre');

        return response()->json([
            'venta_item_id' => $item->id,
            'metodo_captura' => $item->metodo_captura,
            'unidad' => [
                'id' => $item->unidad->id,
                'codigo_barras' => $item->unidad->codigo_barras,
                'estado' => $item->unidad->estado,
            ],
            'referencia' => [
                'id' => $item->unidad->referencia->id,
                'codigo_referencia' => $item->unidad->referencia->codigo_referencia,
                'descripcion' => $item->unidad->referencia->descripcion,
                'marca' => $item->unidad->referencia->marca->nombre,
                'existencia' => $item->unidad->referencia->existencia,
            ],
        ], 201);
    }

    public function cerrar(Venta $venta): VentaResource
    {
        return new VentaResource($this->cargar($this->ventas->cerrar($venta)));
    }

    /** RF-07 */
    public function reversar(Request $request, Venta $venta): VentaResource
    {
        $datos = $request->validate([
            'tipo' => ['required', Rule::enum(TipoReverso::class)],
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
            'venta_item_id' => ['nullable', 'integer'],
        ]);

        $this->reversos->reversar(
            $venta,
            TipoReverso::from($datos['tipo']),
            $datos['motivo'],
            $datos['venta_item_id'] ?? null,
            $request->user(),
        );

        return new VentaResource($this->cargar($venta->refresh()));
    }

    private function cargar(Venta $venta): Venta
    {
        return $venta->load([
            'usuario:id,name',
            'items' => fn ($q) => $q->orderBy('id'),
            'items.usuario:id,name',
            'items.reversoItem',
            'items.unidad:id,referencia_id,codigo_barras,estado',
            'items.unidad.referencia:id,codigo_referencia,descripcion,precio_venta,existencia,marca_id',
            'items.unidad.referencia.marca:id,nombre',
            'reversos' => fn ($q) => $q->orderBy('id'),
            'reversos.usuario:id,name',
            'reversos.items',
        ]);
    }
}
