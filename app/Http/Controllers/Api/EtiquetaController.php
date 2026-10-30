<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EtiquetaService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** RF-03: etiquetas por unidad, en el tamaño que elige quien imprime. */
class EtiquetaController extends Controller
{
    public function __invoke(Request $request, EtiquetaService $servicio): Response
    {
        $datos = $request->validate([
            'unidad_ids' => ['required', 'array', 'min:1', 'max:500'],
            'unidad_ids.*' => ['integer', 'distinct', 'exists:unidades,id'],
            'tamano' => ['required', Rule::in(array_keys(config('etiquetas.tamanos')))],
        ]);

        $pdf = $servicio->generar($datos['unidad_ids'], $datos['tamano']);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="etiquetas-'.$datos['tamano'].'.pdf"',
        ]);
    }
}
