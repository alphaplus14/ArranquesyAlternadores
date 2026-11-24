<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EtiquetaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** RF-03: etiquetas por unidad, en el tamaño que elige quien imprime. */
class EtiquetaController extends Controller
{
    /** Tamaños disponibles (S-05): el frontend los lee de aquí; config/etiquetas.php es la única fuente. */
    public function tamanos(): JsonResponse
    {
        return response()->json(collect(config('etiquetas.tamanos'))
            ->map(fn (array $t, string $clave) => ['clave' => $clave, ...$t])
            ->values());
    }

    public function generar(Request $request, EtiquetaService $servicio): Response
    {
        $datos = $request->validate([
            // S-39: o las unidades de un ajuste de alta (ajuste_id), con el mismo selector de tamaño.
            'ajuste_id' => ['nullable', 'integer'],
            'unidad_ids' => ['required_without:ajuste_id', 'array', 'min:1', 'max:500'],
            'unidad_ids.*' => ['integer', 'distinct', 'exists:unidades,id'],
            'tamano' => ['required', Rule::in(array_keys(config('etiquetas.tamanos')))],
        ]);

        $unidadIds = isset($datos['ajuste_id']) ? $servicio->unidadesDeAlta((int) $datos['ajuste_id']) : $datos['unidad_ids'];
        $pdf = $servicio->generar($unidadIds, $datos['tamano']);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="etiquetas-'.$datos['tamano'].'.pdf"',
        ]);
    }
}
