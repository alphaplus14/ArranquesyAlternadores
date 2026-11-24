<?php

namespace App\Http\Controllers\Api;

use App\Enums\MetodoCaptura;
use App\Http\Controllers\Controller;
use App\Http\Resources\DevolucionResource;
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
    public function __construct(private readonly DevolucionService $devoluciones) {}

    /**
     * La venta de la unidad, antes de pedir el motivo. S-34: con un código de proveedor o fabricante
     * responde tipo 'elegir_venta' con las ventas cerradas candidatas (venta=<n.º> filtra a una).
     */
    public function consulta(Request $request): JsonResponse|DevolucionResource
    {
        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:32'],
            'venta' => ['nullable', 'integer', 'min:1'],
        ]);

        $resultado = $this->devoluciones->consultar($datos['codigo'], isset($datos['venta']) ? (int) $datos['venta'] : null);

        return is_array($resultado) ? response()->json($resultado) : new DevolucionResource($resultado);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:32'],
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
            'metodo_captura' => ['required', Rule::enum(MetodoCaptura::class)],
            // S-34: el ítem elegido de la lista de candidatas (código de proveedor o fabricante).
            'venta_item_id' => ['nullable', 'integer'],
        ]);

        $item = $this->devoluciones->registrar(
            $datos['codigo'],
            $datos['motivo'],
            MetodoCaptura::from($datos['metodo_captura']),
            $request->user(),
            isset($datos['venta_item_id']) ? (int) $datos['venta_item_id'] : null,
        );

        return (new DevolucionResource($item))->response()->setStatusCode(201);
    }
}
