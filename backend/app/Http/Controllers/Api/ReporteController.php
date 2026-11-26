<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgotadoResource;
use App\Models\Cliente;
use App\Models\Proveedor;
use App\Services\ReporteService;
use App\Services\StockMinimoService;
use App\Services\VencimientoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * RF-08: agotados por proveedor. RF-22: bajo mínimo. RF-18: informes de compras, ventas a crédito,
 * cartera, cuentas por pagar, estados de cuenta, remisiones pendientes y diferencias. Vencimientos
 * (RF-30). Todo en pantalla: no se exporta (se retiró la exportación a Excel de la adenda v1.3).
 * El estado de cuenta del cliente en Cartera (S-30) usa el mismo cálculo; aquí el contable lo
 * consulta con período.
 */
class ReporteController extends Controller
{
    public function __construct(
        private readonly ReporteService $reportes,
        private readonly VencimientoService $vencimientos,
    ) {}

    /** RF-22 (S-28): productos con existencia <= su mínimo efectivo, mayor faltante primero. */
    public function bajoMinimo(Request $request, StockMinimoService $servicio): JsonResponse
    {
        $filtros = $request->validate([
            'proveedor_id' => ['nullable', 'integer', 'exists:proveedores,id'],
            'categoria_id' => ['nullable', 'integer', 'exists:categorias,id'],
        ]);

        return response()->json($servicio->bajoMinimo($filtros, min((int) $request->query('por_pagina', 50), 200)));
    }

    /** RF-08 / S-47: agotados de todos los proveedores por defecto; proveedor y categoría acotan. */
    public function agotados(Request $request): AnonymousResourceCollection
    {
        $filtros = $request->validate([
            'proveedor_id' => ['nullable', 'integer', 'exists:proveedores,id'],
            'categoria_id' => ['nullable', 'integer', 'exists:categorias,id'], // RF-22: mismo filtro que Bajo mínimo
        ]);

        return AgotadoResource::collection($this->reportes->agotados($filtros, min(max((int) $request->query('por_pagina', 50), 1), 200)));
    }

    /**
     * RF-32 / S-77: historial de precios de compra, en pantalla. El último y el anterior salen
     * cuando la búsqueda identifica un producto (una referencia o un código universal).
     */
    public function preciosCompra(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'proveedor_id' => ['nullable', 'integer', 'exists:proveedores,id'],
            'categoria_id' => ['nullable', 'integer', 'exists:categorias,id'],
            'referencia_id' => ['nullable', 'integer', 'exists:referencias,id'],
            'codigo_universal_id' => ['nullable', 'integer', 'exists:codigos_universales,id'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if (isset($filtros['desde'], $filtros['hasta']) && $filtros['desde'] > $filtros['hasta']) {
            throw ValidationException::withMessages(['hasta' => 'La fecha final no puede ser anterior a la inicial.']);
        }

        $informe = $this->reportes->preciosCompra($filtros, min(max((int) $request->query('por_pagina', 50), 1), 200));

        return response()->json([...$informe['pagina']->toArray(), 'resumen' => $informe['resumen']]);
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

    /** S-37: por período, con saldo anterior; sin período, los últimos días de config. */
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

    /** RF-30: vencidas y por vencer (aviso de config('cartera.aviso_dias') días), por pagar y por cobrar. */
    public function vencimientos(Request $request): JsonResponse
    {
        $tipo = $request->validate(['tipo' => ['nullable', Rule::in([VencimientoService::POR_PAGAR, VencimientoService::POR_COBRAR])]])['tipo'] ?? null;
        $hoy = Carbon::today();
        $dias = (int) config('cartera.aviso_dias');
        $informe = $this->vencimientos->bandeja($hoy, $dias, $tipo);

        return $this->paginar($request, $informe['filas'], [
            'totales' => $informe['totales'],
            'hoy' => $hoy->toDateString(),
            'aviso_dias' => $dias,
        ]);
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
