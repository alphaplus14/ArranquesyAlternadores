<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MovimientoDineroRequest;
use App\Http\Resources\AbonoResource;
use App\Http\Resources\ClienteResource;
use App\Models\AbonoCliente;
use App\Models\Cliente;
use App\Services\AbonoService;
use App\Services\CarteraService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * RF-15: abonos de clientes y su saldo. Registrar: admin y mostrador. Anular: solo admin (S-14).
 * No hay rutas para editar ni borrar abonos.
 */
class AbonoController extends Controller
{
    public function __construct(
        private readonly AbonoService $abonos,
        private readonly CarteraService $cartera,
    ) {}

    public function store(MovimientoDineroRequest $request, Cliente $cliente): JsonResponse
    {
        $abono = $this->abonos->registrar($cliente, $request->validated(), $request->user());

        return (new AbonoResource($abono->load('usuario:id,name', 'anulacion')))->response()->setStatusCode(201);
    }

    public function anular(Request $request, AbonoCliente $abono): JsonResponse
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:500']]);

        $abono = $this->abonos->anular($abono, $datos['motivo'], $request->user());

        return (new AbonoResource($abono->load('usuario:id,name', 'anulacion.usuario:id,name')))->response()->setStatusCode(201);
    }

    /** Saldo, vencido y antigüedad del cliente a hoy, con sus abonos más recientes. */
    public function saldo(Cliente $cliente): JsonResponse
    {
        $hoy = Carbon::today();
        $antiguedad = $this->cartera->saldoCliente($cliente, $hoy);

        $recientes = AbonoCliente::query()
            ->where('cliente_id', $cliente->id)
            ->with('usuario:id,name', 'anulacion.usuario:id,name')
            ->orderByDesc('fecha')->orderByDesc('id')
            ->limit(10)
            ->get();

        return response()->json([
            'cliente' => new ClienteResource($cliente),
            'corte' => $hoy->toDateString(),
            ...$antiguedad->resumen(),
            'documentos' => $antiguedad->documentos,
            'abonos_recientes' => AbonoResource::collection($recientes),
        ]);
    }
}
