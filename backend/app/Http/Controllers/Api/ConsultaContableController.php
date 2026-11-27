<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\Proveedor;
use App\Services\ConsultaContableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S-88 (RF-17, RF-18, RF-29): consultas del módulo contable, solo lectura y solo en pantalla.
 * Grupo `rol:contable` (el admin pasa); mostrador y logística, 403. Sin rutas de escritura.
 */
class ConsultaContableController extends Controller
{
    public function __construct(private readonly ConsultaContableService $consultas) {}

    /** Historial de pagos de un proveedor (vigentes por defecto; anulados o todos). */
    public function pagosProveedor(Request $request, Proveedor $proveedor): JsonResponse
    {
        return response()->json($this->consultas->historialPagos($proveedor, $this->filtros($request), $request, $this->porPagina($request)));
    }

    /** Historial de abonos de un cliente (vigentes por defecto; anulados o todos). */
    public function abonosCliente(Request $request, Cliente $cliente): JsonResponse
    {
        return response()->json($this->consultas->historialAbonos($cliente, $this->filtros($request), $request, $this->porPagina($request)));
    }

    /** Saldo, vencido, antigüedad y ventas con pendiente del cliente: mismo contrato que el del proveedor. */
    public function saldoCliente(Cliente $cliente): JsonResponse
    {
        return response()->json($this->consultas->saldoCliente($cliente));
    }

    /** @return array{desde: ?string, hasta: ?string, estado: string, cuenta_pago_id: ?int, q: ?string} */
    private function filtros(Request $request): array
    {
        $d = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'estado' => ['nullable', 'in:vigentes,anulados,todos'],
            'cuenta_pago_id' => ['nullable', 'integer', 'exists:cuentas_pago,id'],
            'q' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], ['hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.']);

        return [
            'desde' => $d['desde'] ?? null,
            'hasta' => $d['hasta'] ?? null,
            'estado' => $d['estado'] ?? 'vigentes',
            'cuenta_pago_id' => isset($d['cuenta_pago_id']) ? (int) $d['cuenta_pago_id'] : null,
            'q' => $d['q'] ?? null,
        ];
    }

    private function porPagina(Request $request): int
    {
        return (int) ($request->query('por_pagina', 50));
    }
}
