<?php

namespace App\Http\Controllers\Api;

use App\Enums\Rol;
use App\Http\Controllers\Controller;
use App\Http\Resources\CierreCajaResource;
use App\Http\Resources\MovimientoCajaResource;
use App\Http\Resources\MovimientoEmpleadoResource;
use App\Http\Resources\RecaudoDocumentoResource;
use App\Models\CierreCaja;
use App\Models\MovimientoCaja;
use App\Models\MovimientoEmpleado;
use App\Models\RecaudoDocumento;
use App\Services\CajaService;
use App\Services\CierreCajaService;
use App\Support\RangoFechas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * RF-25 (S-49 a S-54): día de caja, apertura, cierre, reapertura y cuadre mensual. Solo en pantalla.
 * Roles (rutas): consultar el día, mostrador y admin; abrir y cerrar, solo mostrador; reabrir y el mes, solo
 * admin. Logística 403. Sin rutas DELETE.
 */
class CajaController extends Controller
{
    public function __construct(
        private readonly CajaService $caja,
        private readonly CierreCajaService $cierres,
    ) {}

    /** Resumen del día + movimientos + cierre vigente (y los reabiertos), y ventas abiertas que impiden cerrar. */
    public function dia(string $fecha): JsonResponse
    {
        $this->validarFecha($fecha);

        $movimientos = MovimientoCaja::query()
            ->where('fecha', $fecha)
            ->with('categoria:id,nombre', 'cuenta:id,nombre,tipo', 'usuario:id,name', 'anulacion.usuario:id,name')
            ->orderBy('id')
            ->get();
        // S-76: recaudos de documentos del día, con los demás movimientos.
        $recaudos = RecaudoDocumento::query()
            ->where('fecha', $fecha)
            ->with('cuenta:id,nombre,tipo', 'usuario:id,name', 'anulacion.usuario:id,name')
            ->orderBy('id')
            ->get();
        // RF-27: préstamos y abonos de empleados del día (también por nómina), sin la apertura (no toca
        // la caja). Solo nombre del empleado: nunca su saldo (S-57).
        $empleados = MovimientoEmpleado::query()
            ->where('fecha', $fecha)->where('apertura', false)
            ->with('empleado:id,nombre', 'cuenta:id,nombre', 'usuario:id,name', 'anulacion.usuario:id,name')
            ->orderBy('id')
            ->get();
        $cierres = CierreCaja::query()
            ->where('fecha', $fecha)
            ->with('usuario:id,name', 'reapertura.usuario:id,name')
            ->orderByDesc('id')
            ->get();
        $vigente = $cierres->first(fn (CierreCaja $c) => $c->reapertura === null);
        $resumen = $this->caja->resumenDia($fecha);
        if (request()->user()?->rol === Rol::Mostrador) { // S-88: sin dueños ni saldos de billeteras
            $resumen = $this->caja->sinDuenosNiSaldos($resumen);
        }

        return response()->json([
            'fecha' => $fecha,
            'hoy' => RangoFechas::hoy(),
            'resumen' => $resumen,
            'movimientos' => MovimientoCajaResource::collection($movimientos),
            'recaudos' => RecaudoDocumentoResource::collection($recaudos),
            'movimientos_empleado' => MovimientoEmpleadoResource::collection($empleados),
            'cierre' => $vigente ? new CierreCajaResource($vigente) : null,
            'cierres_reabiertos' => CierreCajaResource::collection($cierres->filter(fn (CierreCaja $c) => $c->reapertura !== null)->values()),
            'ventas_abiertas' => $vigente ? [] : $this->cierres->ventasAbiertas($fecha)->map(fn ($v) => [
                'id' => $v->id,
                'forma_pago' => $v->forma_pago,
                'items_count' => $v->items_count,
                'usuario' => $v->usuario?->name,
            ])->values(),
        ]);
    }

    public function cerrar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'fecha' => ['required', 'date_format:Y-m-d'],
            'efectivo_contado' => ['required', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,2'],
            'observacion' => ['nullable', 'string', 'max:255'],
        ], [
            'fecha.required' => 'Indique el día que va a cerrar.',
            'efectivo_contado.required' => 'Escriba el efectivo contado.',
            'efectivo_contado.numeric' => 'El efectivo contado debe ser un número.',
            'efectivo_contado.min' => 'El efectivo contado no puede ser negativo.',
            'observacion.max' => 'La observación admite máximo 255 caracteres.',
        ]);

        $cierre = $this->cierres->cerrar($datos['fecha'], (string) $datos['efectivo_contado'], $datos['observacion'] ?? null, $request->user());

        return (new CierreCajaResource($cierre->load('usuario:id,name', 'reapertura')))->response()->setStatusCode(201);
    }

    /**
     * S-84 ("Cambiar apertura"; antes S-49): el mostrador cambia a mano el efectivo con que abre la
     * caja de ese día (solo mientras no esté cerrado). Motivo obligatorio si difiere de la apertura del
     * cierre anterior (lo valida CajaService). Responde la apertura efectiva.
     */
    public function fijarApertura(Request $request, string $fecha): JsonResponse
    {
        $this->validarFecha($fecha);
        $datos = $request->validate([
            'valor' => ['required', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,2'],
            'motivo' => ['nullable', 'string', 'max:200'],
        ], [
            'motivo.max' => 'El motivo admite máximo 200 caracteres.',
            'valor.required' => 'Escriba el efectivo con que abre la caja (puede ser 0).',
            'valor.numeric' => 'La apertura debe ser un número.',
            'valor.min' => 'La apertura no puede ser negativa.',
            'valor.decimal' => 'La apertura admite máximo 2 decimales.',
        ]);

        return response()->json(['apertura' => $this->caja->fijarApertura($fecha, (string) $datos['valor'], $datos['motivo'] ?? null, $request->user())]);
    }

    /** S-53 / S-54: la única escritura del admin en caja. */
    public function reabrir(Request $request, CierreCaja $cierre): JsonResponse
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:500']], [
            'motivo.required' => 'Escriba el motivo de la reapertura.',
            'motivo.min' => 'El motivo debe tener al menos 3 caracteres.',
        ]);

        $this->cierres->reabrir($cierre, $datos['motivo'], $request->user());

        return (new CierreCajaResource($cierre->refresh()->load('usuario:id,name', 'reapertura.usuario:id,name')))->response()->setStatusCode(201);
    }

    public function mes(string $mes): JsonResponse
    {
        Validator::make(['mes' => $mes], ['mes' => ['required', 'date_format:Y-m']], [
            'mes.date_format' => 'El mes va en formato aaaa-mm.',
        ])->validate();

        if ($mes > substr(RangoFechas::hoy(), 0, 7)) {
            return response()->json(['message' => 'El mes no puede ser futuro.', 'errors' => ['mes' => ['El mes no puede ser futuro.']]], 422);
        }

        return response()->json($this->caja->resumenMes($mes));
    }

    private function validarFecha(string $fecha): void
    {
        Validator::make(['fecha' => $fecha], ['fecha' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.RangoFechas::hoy()]], [
            'fecha.date_format' => 'La fecha va en formato aaaa-mm-dd.',
            'fecha.before_or_equal' => 'La fecha no puede ser futura.',
        ])->validate();
    }
}
