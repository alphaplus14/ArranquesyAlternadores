<?php

namespace App\Services;

use App\Http\Resources\AbonoResource;
use App\Http\Resources\PagoResource;
use App\Models\AbonoCliente;
use App\Models\Cliente;
use App\Models\PagoProveedor;
use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * S-88 (RF-17, RF-18, RF-29): consultas de solo lectura del módulo contable. Historial de pagos de
 * un proveedor y de abonos de un cliente (vigentes, anulados o todos) y saldo del cliente con el
 * mismo contrato que el del proveedor. No escribe ni cambia cálculos: los saldos salen de
 * CarteraService / Antiguedad (S-30, S-32, S-73/S-74, S-79).
 */
class ConsultaContableService
{
    private const ESCALA = 2;

    public function __construct(private readonly CarteraService $cartera) {}

    /**
     * @param  array{desde?: ?string, hasta?: ?string, estado?: ?string, cuenta_pago_id?: ?int, q?: ?string}  $filtros
     * @return array<string, mixed>
     */
    public function historialPagos(Proveedor $proveedor, array $filtros, Request $peticion, int $porPagina): array
    {
        $base = fn () => PagoProveedor::query()
            ->where('pagos_proveedores.proveedor_id', $proveedor->id)
            ->tap(fn (Builder $q) => $this->filtrosComunes($q, 'pagos_proveedores', $filtros))
            ->when($this->texto($filtros), fn (Builder $q, string $like) => $q->where(fn (Builder $w) => $w
                ->where('pagos_proveedores.referencia', 'like', $like)
                ->orWhere('pagos_proveedores.nota', 'like', $like)
                ->orWhereHas('ingreso', fn (Builder $i) => $i->where('numero_documento', 'like', $like))));

        return $this->historial($base, 'pagos_proveedores', 'pago_anulaciones', 'pago_id', $filtros, $porPagina,
            ['usuario:id,name', 'anulacion.usuario:id,name', 'cuenta:id,nombre,tipo', 'ingreso:id,tipo,numero_documento'],
            fn ($m) => (new PagoResource($m))->resolve($peticion));
    }

    /**
     * @param  array{desde?: ?string, hasta?: ?string, estado?: ?string, cuenta_pago_id?: ?int, q?: ?string}  $filtros
     * @return array<string, mixed>
     */
    public function historialAbonos(Cliente $cliente, array $filtros, Request $peticion, int $porPagina): array
    {
        $base = fn () => AbonoCliente::query()
            ->where('abonos_clientes.cliente_id', $cliente->id)
            ->tap(fn (Builder $q) => $this->filtrosComunes($q, 'abonos_clientes', $filtros))
            ->when($this->texto($filtros), fn (Builder $q, string $like) => $q->where(fn (Builder $w) => $w
                ->where('abonos_clientes.referencia', 'like', $like)
                ->orWhere('abonos_clientes.nota', 'like', $like)
                ->orWhereHas('venta', fn (Builder $v) => $v->where('factura_contapyme', 'like', $like))
                ->when(ctype_digit(ltrim(trim((string) $filtros['q']), '#')), fn (Builder $o) => $o->orWhere('abonos_clientes.venta_id', (int) ltrim(trim((string) $filtros['q']), '#')))));

        return $this->historial($base, 'abonos_clientes', 'abono_anulaciones', 'abono_id', $filtros, $porPagina,
            ['usuario:id,name', 'anulacion.usuario:id,name', 'cuenta:id,nombre,tipo', 'venta:id,factura_contapyme,tipo_documento'],
            fn ($m) => (new AbonoResource($m))->resolve($peticion));
    }

    /**
     * Saldo del cliente a hoy con el contrato de GET /proveedores/{id}/saldo: resumen, rangos y las
     * ventas a crédito cerradas con pendiente > 0 (incluidas las de solo valor), con tipo y número.
     *
     * @return array<string, mixed>
     */
    public function saldoCliente(Cliente $cliente): array
    {
        $hoy = Carbon::today();
        $antiguedad = $this->cartera->saldoCliente($cliente, $hoy);

        return [
            'cliente' => $cliente->only('id', 'nombre', 'documento', 'telefono', 'plazo_dias', 'activo'),
            'corte' => $hoy->toDateString(),
            ...$antiguedad->resumen(),
            'documentos' => collect($antiguedad->documentos)->map(fn (array $d) => [
                'id' => $d['id'],
                // Solo valor: su tipo; con ítems, "factura" si lleva número de Contapyme (si no, null).
                'tipo' => $d['tipo_documento'] ?? ($d['factura_contapyme'] ? 'factura' : null),
                'numero_documento' => $d['factura_contapyme'] ?? null,
                'documento' => $d['documento'] ?? null,
                'fecha' => $d['fecha'],
                'vence_el' => $d['vence_el'],
                'valor' => $d['valor'],
                'abonado' => $d['abonado'],
                'pendiente' => $d['pendiente'],
                'dias_vencido' => $d['dias_vencido'],
                'dias_para_vencer' => $d['dias_para_vencer'],
                'rango' => $d['rango'],
            ])->values()->all(),
        ];
    }

    /** Fecha y cuenta, en la consulta (índice (titular, fecha)). */
    private function filtrosComunes(Builder $q, string $tabla, array $filtros): void
    {
        $q->when($filtros['desde'] ?? null, fn (Builder $w, $v) => $w->where("{$tabla}.fecha", '>=', $v))
            ->when($filtros['hasta'] ?? null, fn (Builder $w, $v) => $w->where("{$tabla}.fecha", '<=', $v))
            ->when($filtros['cuenta_pago_id'] ?? null, fn (Builder $w, $v) => $w->where("{$tabla}.cuenta_pago_id", $v));
    }

    private function texto(array $filtros): ?string
    {
        $q = trim((string) ($filtros['q'] ?? ''));

        return $q === '' ? null : '%'.addcslashes($q, '%_\\').'%';
    }

    /**
     * Página de movimientos (más reciente primero) con el estado pedido, y totales del filtro (fecha,
     * cuenta y texto) en UNA consulta agregada: vigente (total y cantidad) y anulado (aparte).
     *
     * @param  \Closure(): Builder  $base
     * @param  list<string>  $relaciones
     * @param  callable(mixed): array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    private function historial(\Closure $base, string $tabla, string $anulaciones, string $llave, array $filtros, int $porPagina, array $relaciones, callable $fila): array
    {
        $estado = $filtros['estado'] ?? 'vigentes';
        $pagina = $base()
            ->when($estado === 'vigentes', fn (Builder $q) => $q->whereDoesntHave('anulacion'))
            ->when($estado === 'anulados', fn (Builder $q) => $q->whereHas('anulacion'))
            ->with($relaciones)
            ->orderByDesc("{$tabla}.fecha")->orderByDesc("{$tabla}.id")
            ->paginate($porPagina);

        $t = $base()
            ->leftJoin($anulaciones, "{$anulaciones}.{$llave}", '=', "{$tabla}.id")
            ->toBase()
            ->selectRaw("COALESCE(SUM(CASE WHEN {$anulaciones}.id IS NULL THEN {$tabla}.valor END), 0) AS vigente")
            ->selectRaw("SUM(CASE WHEN {$anulaciones}.id IS NULL THEN 1 ELSE 0 END) AS cantidad")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$anulaciones}.id IS NOT NULL THEN {$tabla}.valor END), 0) AS anulado")
            ->selectRaw("SUM(CASE WHEN {$anulaciones}.id IS NOT NULL THEN 1 ELSE 0 END) AS cantidad_anulados")
            ->first();

        return [
            ...$pagina->toArray(),
            'data' => $pagina->getCollection()->map($fila)->values()->all(),
            'totales' => [
                'vigente' => bcadd((string) $t->vigente, '0', self::ESCALA),
                'cantidad' => (int) $t->cantidad,
                'anulado' => bcadd((string) $t->anulado, '0', self::ESCALA),
                'cantidad_anulados' => (int) $t->cantidad_anulados,
            ],
        ];
    }
}
