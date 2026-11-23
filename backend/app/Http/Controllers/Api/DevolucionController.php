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

    /** La venta de la unidad, antes de pedir el motivo. */
    public function consulta(Request $request): DevolucionResource
    {
        $datos = $request->validate(['codigo' => ['required', 'string', 'max:32']]);

        return new DevolucionResource($this->devoluciones->consultar($datos['codigo']));
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:32'],
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
            'metodo_captura' => ['required', Rule::enum(MetodoCaptura::class)],
        ]);

        $item = $this->devoluciones->registrar(
            $datos['codigo'],
            $datos['motivo'],
            MetodoCaptura::from($datos['metodo_captura']),
            $request->user(),
        );

        return (new DevolucionResource($item))->response()->setStatusCode(201);
    }
}
