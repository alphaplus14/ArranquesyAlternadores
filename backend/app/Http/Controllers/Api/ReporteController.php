<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReferenciaResource;
use App\Models\Cliente;
use App\Models\Proveedor;
use App\Models\Referencia;
use App\Services\ReporteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * RF-08: agotados por proveedor. RF-18: informes de compras, ventas a crédito, cartera, cuentas
 * por pagar, estados de cuenta, remisiones pendientes y diferencias. Solo en pantalla: no hay
 * exportación. Paginados en el servidor; los totales cubren todas las filas, no solo la página.
 */
class ReporteController extends Controller
{
    public function __construct(private readonly ReporteService $reportes) {}

    public function agotados(Request $request): AnonymousResourceCollection
    {
        $datos = $request->validate(['proveedor_id' => ['required', 'integer', 'exists:proveedores,id']]);

        // Índice (proveedor_id, existencia).
        $pagina = Referencia::query()
            ->where('proveedor_id', $datos['proveedor_id'])
            ->where('existencia', 0)
            ->with(['categoria:id,nombre,admite_codigo_universal', 'codigoUniversal:id,codigo'])
            ->orderBy('codigo_referencia')
            ->paginate(min((int) $request->query('por_pagina', 50), 200));

        return ReferenciaResource::collection($pagina);
    }

    public function compras(Request $request): JsonResponse
    {
        [$desde, $hasta] = $this->periodo($request, Carbon::today()->startOfMonth());
        $proveedorId = $request->validate(['proveedor_id' => ['nullable', 'integer', 'exists:proveedores,id']])['proveedor_id'] ?? null;

        $informe = $this->reportes->compras($desde, $hasta, $proveedorId);

        return $this->paginar($request, $informe['filas'], ['totales' => $informe['totales'], ...$this->rango($desde, $hasta)]);
    }

    public function ventasCredito(Request $request): JsonResponse
    {
        [$desde, $hasta] = $this->periodo($request, Carbon::today()->startOfMonth());
        $clienteId = $request->validate(['cliente_id' => ['nullable', 'integer', 'exists:clientes,id']])['cliente_id'] ?? null;

        $informe = $this->reportes->ventasCredito($desde, $hasta, $clienteId);

        return $this->paginar($request, $informe['filas'], ['totales' => $informe['totales'], ...$this->rango($desde, $hasta)]);
    }

    public function carteraClientes(Request $request): JsonResponse
    {
        $corte = $this->corte($request);
        $informe = $this->reportes->carteraClientes($corte);

        return $this->paginar($request, $informe['filas'], ['totales' => $informe['totales'], 'corte' => $corte->toDateString()]);
    }

    public function cuentasPorPagar(Request $request): JsonResponse
    {
        $corte = $this->corte($request);
        $informe = $this->reportes->cuentasPorPagar($corte);

        return $this->paginar($request, $informe['filas'], ['totales' => $informe['totales'], 'corte' => $corte->toDateString()]);
    }

    /** S-26: por período, con saldo anterior; sin período, los últimos días de config. */
    public function estadoCuentaCliente(Request $request, Cliente $cliente): JsonResponse
    {
        [$desde, $hasta] = $this->periodo($request, Carbon::today()->subDays(config('cartera.dias_estado_cuenta')));
        $informe = $this->reportes->estadoCuentaCliente($cliente, $desde, $hasta);

        return $this->paginar($request, $informe['filas'], [
            'cliente' => $cliente->only('id', 'nombre', 'documento', 'telefono', 'plazo_dias', 'activo'),
            'saldo_anterior' => $informe['saldo_anterior'],
            'totales' => $informe['totales'],
            ...$this->rango($desde, $hasta),
        ]);
    }

    public function estadoCuentaProveedor(Request $request, Proveedor $proveedor): JsonResponse
    {
        [$desde, $hasta] = $this->periodo($request, Carbon::today()->subDays(config('cartera.dias_estado_cuenta')));
        $informe = $this->reportes->estadoCuentaProveedor($proveedor, $desde, $hasta);

        return $this->paginar($request, $informe['filas'], [
            'proveedor' => $proveedor->only('id', 'nombre', 'nit', 'plazo_dias'),
            'saldo_anterior' => $informe['saldo_anterior'],
            'totales' => $informe['totales'],
            ...$this->rango($desde, $hasta),
        ]);
    }

    public function remisionesPendientes(Request $request): JsonResponse
    {
        $proveedorId = $request->validate(['proveedor_id' => ['nullable', 'integer', 'exists:proveedores,id']])['proveedor_id'] ?? null;
        $informe = $this->reportes->remisionesPendientes($proveedorId);

        return $this->paginar($request, $informe['filas'], ['totales' => $informe['totales']]);
    }

    public function diferenciasRemision(Request $request): JsonResponse
    {
        $proveedorId = $request->validate(['proveedor_id' => ['nullable', 'integer', 'exists:proveedores,id']])['proveedor_id'] ?? null;
        $informe = $this->reportes->diferenciasRemision($proveedorId);

        return $this->paginar($request, $informe['filas'], ['totales' => $informe['totales']]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function periodo(Request $request, Carbon $desdePorDefecto): array
    {
        $datos = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $desde = isset($datos['desde']) ? Carbon::parse($datos['desde']) : $desdePorDefecto;
        $hasta = isset($datos['hasta']) ? Carbon::parse($datos['hasta']) : Carbon::today();

        if ($desde->gt($hasta)) {
            throw ValidationException::withMessages(['hasta' => 'La fecha final no puede ser anterior a la inicial.']);
        }

        return [$desde, $hasta];
    }

    private function corte(Request $request): Carbon
    {
        $corte = $request->validate(['corte' => ['nullable', 'date_format:Y-m-d']])['corte'] ?? null;

        return $corte ? Carbon::parse($corte) : Carbon::today();
    }

    /** @return array{desde: string, hasta: string} */
    private function rango(Carbon $desde, Carbon $hasta): array
    {
        return ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()];
    }

    /**
     * @param  Collection<int, mixed>  $filas
     * @param  array<string, mixed>  $extra
     */
    private function paginar(Request $request, Collection $filas, array $extra): JsonResponse
    {
        $porPagina = min(max((int) $request->query('por_pagina', 50), 1), 200);
        $pagina = LengthAwarePaginator::resolveCurrentPage();
        $paginador = new LengthAwarePaginator($filas->forPage($pagina, $porPagina)->values(), $filas->count(), $porPagina, $pagina);

        return response()->json([...$paginador->toArray(), ...$extra]);
    }
}
