<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Auditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** RF-11: historial de ediciones. Solo lectura. */
class AuditoriaController extends Controller
{
    public const TABLAS = ['referencias', 'codigos_universales', 'marcas', 'proveedores', 'categorias', 'users'];

    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'tabla' => ['nullable', Rule::in(self::TABLAS)],
            'registro_id' => ['nullable', 'integer', 'required_with:tabla'],
        ]);

        $pagina = Auditoria::query()
            ->with('usuario:id,name')
            ->when($datos['tabla'] ?? null, fn ($q, $v) => $q->where('tabla', $v))
            ->when($datos['registro_id'] ?? null, fn ($q, $v) => $q->where('registro_id', $v))
            ->orderByDesc('id')
            ->paginate(50);

        return response()->json($pagina);
    }
}
