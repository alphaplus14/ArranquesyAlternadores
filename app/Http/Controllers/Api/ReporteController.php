<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReferenciaResource;
use App\Models\Referencia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** RF-08: agotados por proveedor. Único reporte dentro del alcance. */
class ReporteController extends Controller
{
    public function agotados(Request $request): AnonymousResourceCollection
    {
        $datos = $request->validate(['proveedor_id' => ['required', 'integer', 'exists:proveedores,id']]);

        // Índice (proveedor_id, existencia).
        $pagina = Referencia::query()
            ->where('proveedor_id', $datos['proveedor_id'])
            ->where('existencia', 0)
            ->with(['marca:id,nombre', 'categoria:id,nombre,admite_codigo_universal', 'codigoUniversal:id,codigo'])
            ->orderBy('codigo_referencia')
            ->paginate(min((int) $request->query('por_pagina', 50), 200));

        return ReferenciaResource::collection($pagina);
    }
}
