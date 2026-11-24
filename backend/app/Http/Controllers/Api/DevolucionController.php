<?php

namespace App\Http\Controllers\Api;

use App\Enums\MetodoCaptura;
use App\Http\Controllers\Controller;
use App\Http\Resources\DevolucionResource;
use App\Services\CarteraService;
use App\Services\DevolucionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * RF-07 / S-27: devolución de un cliente registrada por logística o admin con el código local de
 * la unidad. La ruta de reversos de mostrador (POST /ventas/{venta}/reversos) no cambia.
 */
class DevolucionController extends Controller
{
    public function __construct(
        private readonly DevolucionService $devoluciones,
        private readonly CarteraService $cartera,
    ) {}

    /**
     * La venta de la unidad, antes de pedir el motivo. S-34: con un código de proveedor o fabricante
     * responde tipo 'elegir_venta' con las ventas cerradas candidatas (venta=<n.º> filtra a una).
     */
    public function consulta(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:32'],
            'venta' => ['nullable', 'integer', 'min:1'],
        ]);

        $resultado = $this->devoluciones->consultar($datos['codigo'], isset($datos['venta']) ? (int) $datos['venta'] : null);

        if (is_array($resultado)) {
            return response()->json($resultado);
        }

        // S-38: en una venta a crédito, el saldo actual del cliente (para mostrar el antes y el después).
        $cliente = $resultado->venta->esCredito() ? $resultado->venta->cliente : null;

        return response()->json([
            ...(new DevolucionResource($resultado))->resolve($request),
            'saldo_actual' => $cliente ? $this->cartera->saldoDe($cliente) : null,
        ]);
    }

    /**
     * RF-07 / S-38: registra la devolución por la etiqueta (una unidad), por un ítem elegido (S-34) o
     * por un grupo (venta, referencia, precio) con cantidad. El motivo es opcional en este flujo: sin
     * él se guarda "Devolución del cliente"; con nota, "{motivo} — {nota}". Responde el primer ítem
     * (misma forma que antes), los códigos devueltos y el saldo del cliente antes y después.
     */
    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'codigo' => ['nullable', 'string', 'max:32', 'required_without:venta_id'],
            'metodo_captura' => ['required', Rule::enum(MetodoCaptura::class)],
            'motivo' => ['nullable', 'string', 'max:500'],
            'nota' => ['nullable', 'string', 'max:255'],
            // S-34: un ítem elegido de la lista.
            'venta_item_id' => ['nullable', 'integer'],
            // S-38: un grupo elegido de la lista, con cantidad.
            'venta_id' => ['nullable', 'integer'],
            'referencia_id' => ['nullable', 'integer', 'required_with:venta_id'],
            'precio_unitario' => ['nullable', 'numeric', 'required_with:venta_id'],
            'cantidad' => ['nullable', 'integer'],
        ]);

        $r = $this->devoluciones->registrar($datos, MetodoCaptura::from($datos['metodo_captura']), $request->user());
        $items = $r['items'];

        return response()->json([
            ...(new DevolucionResource($items->first()))->resolve($request),
            'cantidad' => $items->count(),
            'total' => number_format((float) $items->sum(fn ($i) => (float) $i->precio_unitario), 2, '.', ''),
            'devueltas' => $items->map(fn ($i) => $i->unidad->codigo_barras)->values(),
            'saldo_cliente' => $r['saldo_antes'] === null ? null : ['antes' => $r['saldo_antes'], 'despues' => $r['saldo_despues']],
        ], 201);
    }
}
