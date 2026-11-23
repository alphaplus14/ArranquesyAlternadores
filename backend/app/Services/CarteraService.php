<?php

namespace App\Services;

use App\Enums\EstadoVenta;
use App\Enums\FormaPago;
use App\Models\Cliente;
use App\Support\RangoFechas;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * RF-15 / RF-18 (S-09, S-11, S-12, S-13, S-30): única fuente del saldo de los clientes. Todo se
 * calcula en SQL con agregados y funciones de ventana (MySQL 8), sin traer las ventas a memoria.
 *
 * A una fecha de corte (día de Bogotá; por defecto hoy):
 * - Valor de una venta = suma de precio_unitario de sus ítems no reversados hasta el corte. Solo
 *   ventas a crédito CERRADAS antes del fin del día de corte (S-09). Sin precio (ventas antiguas) = 0.
 * - Abonado = abonos con fecha <= corte y sin anulación hasta el corte.
 * - Saldo = ventas − abonado. Negativo = saldo a favor.
 * - FIFO (S-12): los abonos se aplican a las ventas más antiguas (por cierre, luego id). Pendiente
 *   de cada venta = max(0, min(neto, acumulado − abonado)). No se guarda la asignación.
 * - Vencido = pendiente de las ventas con vence_el < corte. Rangos (S-13): al día (incluye las que
 *   vencen hoy), 1-30, 31-60, 61-90 y más de 90 días vencidos.
 */
class CarteraService
{
    public const RANGOS = ['al_dia', 'd1_30', 'd31_60', 'd61_90', 'mas_90'];

    private const POR_PAGINA = 25;

    /**
     * Clientes con saldo, vencido, próximo vencimiento y antigüedad, mayor saldo primero, más los
     * totales del conjunto filtrado.
     *
     * @param  array{busqueda?: string|null, solo_con_saldo?: bool, solo_vencidos?: bool, corte?: string|null}  $filtros
     * @return array{pagina: LengthAwarePaginator, totales: array<string, mixed>, corte: string}
     */
    public function listar(array $filtros, int $porPagina = self::POR_PAGINA, int $pagina = 0): array
    {
        $corte = $filtros['corte'] ?? null ?: RangoFechas::hoy();

        // Una sola consulta: una fila AGREGADA por cliente (no las ventas). Los filtros, los totales,
        // el orden y la página se resuelven sobre esas filas, sin repetir el cálculo FIFO.
        $filas = DB::query()->fromSub($this->porCliente($corte, $filtros['busqueda'] ?? null), 'c')
            ->when($filtros['solo_con_saldo'] ?? false, fn ($q) => $q->where('c.saldo', '>', 0))
            ->when($filtros['solo_vencidos'] ?? false, fn ($q) => $q->where('c.vencido', '>', 0))
            ->get()
            // Mayor saldo primero (como número: MySQL lo entrega como texto), luego nombre e id.
            ->sort(fn ($a, $b) => [(float) $b->saldo, $a->nombre, (int) $a->id] <=> [(float) $a->saldo, $b->nombre, (int) $b->id])
            ->values();

        $suma = fn (string $campo, ?callable $f = null) => $filas->sum(fn ($c) => $f ? $f((float) $c->{$campo}) : (float) $c->{$campo});
        $totales = [
            'cartera_total' => self::pesos($suma('saldo', fn ($v) => max($v, 0))),
            'saldo_a_favor' => self::pesos(abs($suma('saldo', fn ($v) => min($v, 0)))),
            'vencido_total' => self::pesos($suma('vencido')),
            'clientes_con_saldo' => $filas->filter(fn ($c) => (float) $c->saldo > 0)->count(),
            'clientes_vencidos' => $filas->filter(fn ($c) => (float) $c->vencido > 0)->count(),
            'antiguedad' => array_combine(self::RANGOS, array_map(fn ($r) => self::pesos($suma($r)), self::RANGOS)),
        ];

        $pagina = $pagina > 0 ? $pagina : LengthAwarePaginator::resolveCurrentPage();
        $paginador = new LengthAwarePaginator(
            $filas->forPage($pagina, $porPagina)->map(fn ($f) => $this->filaCliente($f))->values(),
            $filas->count(),
            $porPagina,
            $pagina,
            ['path' => LengthAwarePaginator::resolveCurrentPath()],
        );

        return ['pagina' => $paginador, 'totales' => $totales, 'corte' => $corte];
    }

    /** Saldo, vencido, próximo vencimiento y antigüedad de un cliente. */
    public function resumenDe(Cliente $cliente, ?string $corte = null): array
    {
        $corte = $corte ?: RangoFechas::hoy();
        $fila = DB::query()->fromSub($this->porCliente($corte, null, $cliente->id), 'c')->first();

        return $this->filaCliente($fila) + ['corte' => $corte];
    }

    /** Saldo de un cliente a hoy (S-30: la única fuente). */
    public function saldoDe(Cliente $cliente): string
    {
        return $this->resumenDe($cliente)['saldo'];
    }

    /**
     * Una fila por cliente: ventas, abonado, saldo, vencido, próximo vencimiento y rangos.
     * Con búsqueda, filtra por prefijo de nombre o documento (índices; sin comodín inicial).
     */
    protected function porCliente(string $corte, ?string $busqueda = null, ?int $clienteId = null): Builder
    {
        $busqueda = trim((string) $busqueda);
        $prefijo = addcslashes($busqueda, '%_\\').'%';
        $porVenta = $this->ventasFifo($corte, $clienteId);

        $agregado = DB::query()->fromSub($porVenta, 'f')
            ->groupBy('f.cliente_id')
            ->selectRaw('f.cliente_id,
                SUM(f.neto) AS ventas,
                SUM(CASE WHEN f.dias > 0 THEN f.pendiente ELSE 0 END) AS vencido,
                MIN(CASE WHEN f.pendiente > 0 AND f.dias <= 0 THEN f.vence_el END) AS proximo_vencimiento,
                MIN(CASE WHEN f.pendiente > 0 AND f.dias > 0 THEN f.vence_el END) AS vencida_desde,
                SUM(CASE WHEN f.dias <= 0 THEN f.pendiente ELSE 0 END) AS al_dia,
                SUM(CASE WHEN f.dias BETWEEN 1 AND 30 THEN f.pendiente ELSE 0 END) AS d1_30,
                SUM(CASE WHEN f.dias BETWEEN 31 AND 60 THEN f.pendiente ELSE 0 END) AS d31_60,
                SUM(CASE WHEN f.dias BETWEEN 61 AND 90 THEN f.pendiente ELSE 0 END) AS d61_90,
                SUM(CASE WHEN f.dias > 90 THEN f.pendiente ELSE 0 END) AS mas_90');

        $abonos = $this->abonadoPorCliente($corte, $clienteId);

        return DB::table('clientes as cl')
            ->leftJoinSub($agregado, 'ag', 'ag.cliente_id', '=', 'cl.id')
            ->leftJoinSub($abonos, 'ab', 'ab.cliente_id', '=', 'cl.id')
            // En la lista, solo clientes con algún movimiento a crédito o abono; el resumen de un
            // cliente se devuelve aunque no tenga ninguno.
            ->when(! $clienteId, fn ($q) => $q->where(fn ($w) => $w->whereNotNull('ag.cliente_id')->orWhereNotNull('ab.cliente_id')))
            ->when($clienteId, fn ($q, $id) => $q->where('cl.id', $id))
            ->when($busqueda !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('cl.nombre', 'like', $prefijo)
                ->orWhere('cl.documento', 'like', $prefijo)))
            ->selectRaw('cl.id, cl.nombre, cl.documento, cl.telefono, cl.plazo_dias, cl.activo,
                COALESCE(ag.ventas, 0) AS ventas,
                COALESCE(ab.abonado, 0) AS abonado,
                COALESCE(ag.ventas, 0) - COALESCE(ab.abonado, 0) AS saldo,
                COALESCE(ag.vencido, 0) AS vencido,
                ag.proximo_vencimiento, ag.vencida_desde,
                COALESCE(ag.al_dia, 0) AS al_dia, COALESCE(ag.d1_30, 0) AS d1_30, COALESCE(ag.d31_60, 0) AS d31_60,
                COALESCE(ag.d61_90, 0) AS d61_90, COALESCE(ag.mas_90, 0) AS mas_90');
    }

    /**
     * Una fila por venta a crédito cerrada: neto al corte, abono aplicado (FIFO), pendiente y días
     * vencidos (positivo = vencida).
     */
    protected function ventasFifo(string $corte, ?int $clienteId = null): Builder
    {
        $abonos = $this->abonadoPorCliente($corte, $clienteId);

        $conAcumulado = DB::query()->fromSub($this->ventasNetas($corte, $clienteId), 'vn')
            ->leftJoinSub($abonos, 'ab', 'ab.cliente_id', '=', 'vn.cliente_id')
            ->selectRaw('vn.*, COALESCE(ab.abonado, 0) AS abonado_cliente,
                SUM(vn.neto) OVER (PARTITION BY vn.cliente_id ORDER BY vn.cerrada_at, vn.id) AS acumulado');

        return DB::query()->fromSub($conAcumulado, 'x')
            ->selectRaw('x.id, x.cliente_id, x.cerrada_at, x.vence_el, x.neto, x.bruto,
                GREATEST(0, LEAST(x.neto, x.acumulado - x.abonado_cliente)) AS pendiente,
                COALESCE(DATEDIFF(?, x.vence_el), 0) AS dias', [$corte]);
    }

    /** Valor de cada venta a crédito cerrada al corte, neto de los reversos hechos hasta el corte. */
    protected function ventasNetas(string $corte, ?int $clienteId = null): Builder
    {
        $fin = RangoFechas::finExclusivo($corte)->format('Y-m-d H:i:s');

        // Índice (forma_pago, cliente_id, vence_el) de ventas; venta_items y reverso_items por FK.
        return DB::table('ventas as v')
            ->join('venta_items as vi', 'vi.venta_id', '=', 'v.id')
            ->leftJoin('reverso_items as ri', 'ri.venta_item_id', '=', 'vi.id')
            ->leftJoin('reversos as r', fn ($j) => $j->on('r.id', '=', 'ri.reverso_id')->where('r.created_at', '<', $fin))
            ->where('v.forma_pago', FormaPago::Credito->value)
            ->where('v.estado', EstadoVenta::Cerrada->value)
            ->whereNotNull('v.cliente_id')
            ->where('v.cerrada_at', '<', $fin)
            ->when($clienteId, fn ($q, $id) => $q->where('v.cliente_id', $id))
            ->groupBy('v.id', 'v.cliente_id', 'v.cerrada_at', 'v.vence_el')
            ->selectRaw('v.id, v.cliente_id, v.cerrada_at, v.vence_el,
                SUM(CASE WHEN r.id IS NULL THEN COALESCE(vi.precio_unitario, 0) ELSE 0 END) AS neto,
                SUM(COALESCE(vi.precio_unitario, 0)) AS bruto');
    }

    /** Abonos vigentes al corte por cliente (índice cliente_id, fecha). */
    protected function abonadoPorCliente(string $corte, ?int $clienteId = null): Builder
    {
        $fin = RangoFechas::finExclusivo($corte)->format('Y-m-d H:i:s');

        return DB::table('abonos_clientes as a')
            ->leftJoin('abono_anulaciones as an', fn ($j) => $j->on('an.abono_id', '=', 'a.id')->where('an.created_at', '<', $fin))
            ->where('a.fecha', '<=', $corte)
            ->whereNull('an.id')
            ->when($clienteId, fn ($q, $id) => $q->where('a.cliente_id', $id))
            ->groupBy('a.cliente_id')
            ->selectRaw('a.cliente_id, SUM(a.valor) AS abonado');
    }

    /** @return array<string, mixed> */
    protected function filaCliente(?object $f): array
    {
        $saldo = $f ? (float) $f->saldo : 0.0;

        return [
            'cliente' => $f ? [
                'id' => (int) $f->id,
                'nombre' => $f->nombre,
                'documento' => $f->documento,
                'telefono' => $f->telefono,
                'plazo_dias' => (int) $f->plazo_dias,
                'activo' => (bool) $f->activo,
            ] : null,
            'ventas' => self::pesos($f->ventas ?? 0),
            'abonado' => self::pesos($f->abonado ?? 0),
            'saldo' => self::pesos($saldo),
            'saldo_a_favor' => $saldo < 0,
            'vencido' => self::pesos($f->vencido ?? 0),
            'proximo_vencimiento' => $f->proximo_vencimiento ?? null,
            'vencida_desde' => $f->vencida_desde ?? null,
            'antiguedad' => array_combine(self::RANGOS, array_map(fn ($r) => self::pesos($f->{$r} ?? 0), self::RANGOS)),
        ];
    }

    /** Pesos con 2 decimales como texto (igual que los decimal:2 de los modelos). */
    protected static function pesos(mixed $valor): string
    {
        return number_format((float) $valor, 2, '.', '');
    }
}
