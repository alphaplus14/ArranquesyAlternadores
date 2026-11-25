<?php

namespace App\Http\Controllers\Api;

use App\Enums\GrupoCaja;
use App\Http\Controllers\Controller;
use App\Http\Resources\MovimientoCajaResource;
use App\Models\CategoriaCaja;
use App\Models\MovimientoCaja;
use App\Services\MovimientoCajaService;
use App\Support\RangoFechas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * RF-26 (S-51 a S-54): movimientos de caja y sus categorías. Registrar y anular movimientos: solo
 * mostrador. Categorías: consulta mostrador y admin; crear y editar, solo admin (sin DELETE).
 */
class MovimientoCajaController extends Controller
{
    public function __construct(private readonly MovimientoCajaService $movimientos) {}

    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate(['fecha' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json(['data' => MovimientoCajaResource::collection(MovimientoCaja::query()
            ->where('fecha', $datos['fecha'] ?? RangoFechas::hoy())
            ->with('categoria:id,nombre', 'cuenta:id,nombre,tipo', 'usuario:id,name', 'anulacion.usuario:id,name')
            ->orderBy('id')
            ->get())]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'categoria_caja_id' => ['required', 'integer'],
            'concepto' => ['required', 'string', 'min:2', 'max:150'],
            'valor' => ['required', 'numeric', 'gt:0', 'max:999999999999', 'decimal:0,2'],
            'cuenta_pago_id' => ['required', 'integer'],
            'referencia' => ['nullable', 'string', 'max:60'],
        ], [
            'categoria_caja_id.required' => 'Elija la categoría.',
            'concepto.required' => 'Escriba el concepto.',
            'concepto.max' => 'El concepto admite máximo 150 caracteres.',
            'valor.required' => 'Escriba el valor.',
            'valor.numeric' => 'El valor debe ser un número.',
            'valor.gt' => 'El valor debe ser mayor que 0.',
            'cuenta_pago_id.required' => 'Elija la cuenta.',
            'referencia.max' => 'La referencia admite máximo 60 caracteres.',
        ]);

        $movimiento = $this->movimientos->registrar($datos, $request->user());

        return (new MovimientoCajaResource($movimiento->load('categoria:id,nombre', 'cuenta:id,nombre,tipo', 'usuario:id,name', 'anulacion')))
            ->response()->setStatusCode(201);
    }

    public function anular(Request $request, MovimientoCaja $movimiento): JsonResponse
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:500']], [
            'motivo.required' => 'Escriba el motivo de la anulación.',
            'motivo.min' => 'El motivo debe tener al menos 3 caracteres.',
        ]);

        $this->movimientos->anular($movimiento, $datos['motivo'], $request->user());

        return (new MovimientoCajaResource($movimiento->load('categoria:id,nombre', 'cuenta:id,nombre,tipo', 'usuario:id,name', 'anulacion.usuario:id,name')))
            ->response()->setStatusCode(201);
    }

    /** Categorías por grupo. El mostrador ve las activas; el admin, con `incluir_inactivas`, todas. */
    public function categorias(Request $request): JsonResponse
    {
        $todas = $request->boolean('incluir_inactivas') && $request->user()->rol->value === 'admin';

        return response()->json([
            'data' => CategoriaCaja::query()
                ->when(! $todas, fn ($q) => $q->where('activa', true))
                ->orderByRaw("FIELD(grupo, 'vario', 'fijo', 'financiero', 'ingreso_externo', 'otro_ingreso')")
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'grupo', 'activa']),
        ]);
    }

    public function crearCategoria(Request $request): JsonResponse
    {
        $datos = $this->validarCategoria($request);

        return response()->json(CategoriaCaja::create($datos)->only('id', 'nombre', 'grupo', 'activa'), 201);
    }

    public function actualizarCategoria(Request $request, CategoriaCaja $categoria): JsonResponse
    {
        $categoria->update($this->validarCategoria($request, $categoria));

        return response()->json($categoria->only('id', 'nombre', 'grupo', 'activa'));
    }

    private function validarCategoria(Request $request, ?CategoriaCaja $categoria = null): array
    {
        $grupo = $request->input('grupo', $categoria?->grupo->value);
        $parcial = $categoria ? 'sometimes' : 'required';
        $datos = $request->validate([
            'nombre' => [$parcial, 'string', 'min:2', 'max:100',
                Rule::unique('categorias_caja', 'nombre')->where('grupo', $grupo)->ignore($categoria?->id)],
            'grupo' => [$parcial, Rule::in(GrupoCaja::valores())],
            'activa' => ['sometimes', 'boolean'],
        ], [
            'nombre.required' => 'Escriba el nombre de la categoría.',
            'nombre.unique' => 'Ya existe esa categoría en el grupo.',
            'grupo.in' => 'Elija un grupo de la lista.',
        ]);
        if (isset($datos['nombre'])) {
            $datos['nombre'] = trim($datos['nombre']);
        }

        return $datos;
    }
}
