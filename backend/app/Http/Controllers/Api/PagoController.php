<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PagoProveedorRequest;
use App\Http\Resources\PagoResource;
use App\Models\PagoProveedor;
use App\Models\Proveedor;
use App\Services\CarteraService;
use App\Services\PagoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * RF-17: pagos a proveedores y su saldo. Admin y contable (S-14, RF-21); anular es solo del admin
 * (S-27). No hay rutas para editar ni borrar pagos.
 */
class PagoController extends Controller
{
    public function __construct(
        private readonly PagoService $pagos,
        private readonly CarteraService $cartera,
    ) {}

    public function store(PagoProveedorRequest $request, Proveedor $proveedor): JsonResponse
    {
        $pago = $this->pagos->registrar($proveedor, $request->validated(), $request->user());

        return (new PagoResource($pago->load('usuario:id,name', 'anulacion', 'ingreso:id,tipo,numero_documento')))->response()->setStatusCode(201);
    }

    public function anular(Request $request, PagoProveedor $pago): JsonResponse
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:500']]);

        $pago = $this->pagos->anular($pago, $datos['motivo'], $request->user());

        return (new PagoResource($pago->load('usuario:id,name', 'anulacion.usuario:id,name')))->response()->setStatusCode(201);
    }

    /** Saldo, vencido y antigüedad del proveedor a hoy, con sus pagos más recientes. */
    public function saldo(Proveedor $proveedor): JsonResponse
    {
        $hoy = Carbon::today();
        $antiguedad = $this->cartera->saldoProveedor($proveedor, $hoy);

        $recientes = PagoProveedor::query()
            ->where('proveedor_id', $proveedor->id)
            ->with('usuario:id,name', 'anulacion.usuario:id,name', 'ingreso:id,tipo,numero_documento')
            ->orderByDesc('fecha')->orderByDesc('id')
            ->limit(10)
            ->get();

        return response()->json([
            'proveedor' => $proveedor->only('id', 'nombre', 'nit', 'plazo_dias'),
            'corte' => $hoy->toDateString(),
            ...$antiguedad->resumen(),
            'documentos' => $antiguedad->documentos,
            'pagos_recientes' => PagoResource::collection($recientes),
        ]);
    }
}
