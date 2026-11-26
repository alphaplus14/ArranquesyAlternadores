<?php

namespace App\Http\Controllers\Api;

use App\Enums\EstadoVenta;
use App\Enums\Rol;
use App\Enums\TipoDocumentoVenta;
use App\Enums\TipoReverso;
use App\Http\Controllers\Controller;
use App\Http\Requests\AbrirVentaRequest;
use App\Http\Requests\CambiarPrecioItemRequest;
use App\Http\Requests\CerrarVentaRequest;
use App\Http\Requests\EscanearVentaRequest;
use App\Http\Requests\RegistrarCreditoRequest;
use App\Http\Resources\VentaItemResource;
use App\Http\Resources\VentaResource;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Services\ReversoService;
use App\Services\VentaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    /**
     * Ventas recientes; por defecto, las del usuario.
     * S-33: total con IVA incluido = suma de los ítems vigentes, en una subconsulta (sin N+1).
     * S-40: con estado=abierta devuelve TODAS las abiertas (del usuario; el admin, de todos), de la
     * más antigua a la más reciente, para retomarlas: la venta de contado del día y las de crédito.
     * items_count cuenta solo los ítems vigentes (sin reverso).
     */
    public function index(Request $request): JsonResponse
    {
        $abiertas = $request->query('estado') === EstadoVenta::Abierta->value;
        $admin = $request->user()->rol === Rol::Admin;

        $pagina = Venta::query()
            ->with('usuario:id,name', 'cliente:id,nombre,plazo_dias')
            ->withCount(['items as items_count' => fn ($q) => $q->whereDoesntHave('reversoItem')])
            // S-73: un registro de solo valor (sin ítems) muestra su valor_total; anulada = tiene reverso.
            ->withExists('reversos as anulada')
            ->addSelect(['total' => DB::table('venta_items as vi')
                ->leftJoin('reverso_items as ri', 'ri.venta_item_id', '=', 'vi.id')
                ->whereColumn('vi.venta_id', 'ventas.id')
                ->whereNull('ri.id')
                ->selectRaw('COALESCE(SUM(vi.precio_unitario), 0)')])
            ->when($request->query('estado'), fn ($q, $v) => $q->where('estado', $v))
            ->when(! ($abiertas ? $admin : $request->boolean('todas')), fn ($q) => $q->where('usuario_id', $request->user()->id))
            ->when($abiertas, fn ($q) => $q->orderBy('id'), fn ($q) => $q->orderByDesc('id'))
            ->paginate($abiertas ? 100 : 25);

        return response()->json($pagina);
    }

    /**
     * RF-14: de contado o a crédito. S-46: la venta nace con su primer escaneo, en una transacción;
     * si la lectura falla (incluido "hay que elegir referencia") no queda ninguna venta. Responde
     * igual que escanear y, además, con la venta completa (la misma forma que GET /ventas/{id}):
     * la pantalla la guarda en caché y la muestra sin pedirla otra vez (RF-06).
     */
    public function store(AbrirVentaRequest $request): JsonResponse
    {
        $items = $this->ventas->abrirYEscanear(
            $request->user(),
            $request->formaPago(),
            $request->validated('cliente_id'),
            $request->validated('factura_contapyme'),
            $request->codigo(),
            $request->metodo(),
            $request->referenciaId(),
            $request->cantidad(),
        );

        return response()->json([
            ...$this->datosEscaneo($request, $items),
            'venta' => new VentaResource($this->cargar($items->last()->venta)),
        ], 201);
    }

    /**
     * RF-14 cambiado (S-73): registra una venta a crédito de solo valor (cliente + factura o remisión +
     * valor total con IVA). Nace cerrada; no toca inventario ni caja.
     */
    public function registrarCredito(RegistrarCreditoRequest $request): JsonResponse
    {
        $venta = $this->ventas->registrarCredito(
            (int) $request->validated('cliente_id'),
            TipoDocumentoVenta::from($request->validated('tipo_documento')),
            (string) $request->validated('valor_total'),
            $request->validated('numero_documento'),
            $request->user(),
        );

        return (new VentaResource($this->cargar($venta)))->response()->setStatusCode(201);
    }

    public function show(Venta $venta): VentaResource
    {
        return new VentaResource($this->cargar($venta));
    }

    public function escanear(EscanearVentaRequest $request, Venta $venta): JsonResponse
    {
        $items = $this->ventas->escanearVarias(
            $venta,
            $request->codigo(),
            $request->metodo(),
            $request->user(),
            $request->referenciaId(),
            $request->cantidad(),
        );

        return response()->json($this->datosEscaneo($request, $items), 201);
    }

    /**
     * RF-06: una sola petición por escaneo. Se devuelve el ítem con la misma forma que en
     * GET /ventas/{id} para que el frontend lo agregue a la venta en caché sin pedirla otra vez.
     * Todas las unidades son de la misma referencia: se carga una vez y se comparte (sin N+1).
     * S-46: la usan escanear y POST /ventas (primer escaneo).
     *
     * @param  Collection<int, VentaItem>  $items
     * @return array<string, mixed>
     */
    private function datosEscaneo(Request $request, Collection $items): array
    {
        $item = $items->last();

        $item->unidad->load(
            'referencia:id,codigo_referencia,calidad,descripcion,precio_venta,existencia,proveedor_id',
            'referencia.proveedor:id,nombre',
        );
        $referencia = $item->unidad->referencia;
        $items->each(fn (VentaItem $i) => $i->setRelation('usuario', $request->user())->setRelation('reversoItem', null)
            ->unidad->setRelation('referencia', $referencia));

        return [
            'venta_id' => $item->venta_id,
            'item' => new VentaItemResource($item),
            // S-40: todos los ítems de la lectura (con cantidad > 1, varios).
            'items' => VentaItemResource::collection($items),
            'venta_item_id' => $item->id,
            'metodo_captura' => $item->metodo_captura,
            'origen_codigo' => $item->origen_codigo, // S-31: etiqueta | referencia
            // S-33: totales de la venta con el ítem nuevo, para que la pantalla los actualice sin
            // volver a pedir la venta (una consulta liviana: precio, IVA y si está reversado).
            'totales' => $this->ventas->totales($item->venta_id)->aArreglo(),
            'unidad' => [
                'id' => $item->unidad->id,
                'codigo_barras' => $item->unidad->codigo_barras,
                'estado' => $item->unidad->estado,
            ],
            'referencia' => [
                'id' => $item->unidad->referencia->id,
                'codigo_referencia' => $item->unidad->referencia->codigo_referencia,
                'descripcion' => $item->unidad->referencia->descripcion,
                'calidad' => $item->unidad->referencia->calidad,
                'proveedor' => $item->unidad->referencia->proveedor->nombre, // S-23: en lugar de la marca
                'existencia' => $item->unidad->referencia->existencia,
            ],
        ];
    }

    /** RF-24 (S-48): de contado con `pagos` que suman el total; a crédito sin pagos. */
    public function cerrar(CerrarVentaRequest $request, Venta $venta): VentaResource
    {
        return new VentaResource($this->cargar($this->ventas->cerrar($venta, $request->pagos())));
    }

    /**
     * S-42: el mismo precio libre para varios ítems de la venta (un grupo del modal de cierre). Una
     * transacción y una fila de auditoría por ítem que cambia. Responde la venta con sus totales.
     */
    public function cambiarPrecioLote(Request $request, Venta $venta): VentaResource
    {
        $datos = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
            'precio_unitario' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
        ], ['precio_unitario.gt' => 'El precio debe ser mayor que 0.']);

        $venta = $this->ventas->cambiarPrecioLote($venta, $datos['ids'], $datos['precio_unitario'], $request->user());

        return new VentaResource($this->cargar($venta));
    }

    /** RF-14 / S-18: precio libre en una venta a crédito abierta; el servicio lo audita. */
    public function cambiarPrecio(CambiarPrecioItemRequest $request, Venta $venta, VentaItem $item): VentaResource
    {
        $venta = $this->ventas->cambiarPrecio($venta, $item, $request->validated('precio_unitario'), $request->user());

        return new VentaResource($this->cargar($venta));
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
            'cliente:id,nombre,documento,plazo_dias',
            'items' => fn ($q) => $q->orderBy('id'),
            'items.usuario:id,name',
            'items.reversoItem',
            'items.unidad:id,referencia_id,codigo_barras,estado',
            'items.unidad.referencia:id,codigo_referencia,calidad,descripcion,precio_venta,existencia,proveedor_id',
            'items.unidad.referencia.proveedor:id,nombre',
            'reversos' => fn ($q) => $q->orderBy('id'),
            'reversos.usuario:id,name',
            'reversos.items',
            'pagos' => fn ($q) => $q->orderBy('id'),
            'pagos.cuenta:id,nombre,tipo',
        ]);
    }
}
