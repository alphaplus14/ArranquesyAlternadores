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
use Illuminate\Support\Facades\DB;

/**
 * S-89 (RF-17, RF-18, RF-29): consultas de solo lectura del módulo contable. Historial de pagos de
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

    /**
     * S-97 (RF-17, RF-18, RF-29): cartera recuperada = abonos de clientes por la fecha del abono, en
     * detalle (una fila por abono) o por cliente (con su saldo actual). Los totales cubren TODAS las
     * filas filtradas y no dependen de la vista, la página ni el estado: recuperado y cantidad de los
     * vigentes, clientes distintos, anulado aparte y el total por cuenta, en UNA consulta agregada
     * (GROUP BY cuenta WITH ROLLUP). No incluye los recaudos de Contapyme (S-76).
     *
     * @param  array<string, mixed>  $filtros  desde, hasta, cliente_id, cuenta_pago_id, estado, tipo_aplicacion, q, vista, orden
     * @return array<string, mixed>
     */
    public function carteraRecuperada(array $filtros, int $porPagina): array
    {
        $base = fn () => AbonoCliente::query()
            ->tap(fn (Builder $q) => $this->filtrosComunes($q, 'abonos_clientes', $filtros))
            ->when($filtros['cliente_id'] ?? null, fn (Builder $q, $v) => $q->where('abonos_clientes.cliente_id', $v))
            ->when(($filtros['tipo_aplicacion'] ?? 'todos') === 'a_factura', fn (Builder $q) => $q->whereNotNull('abonos_clientes.venta_id'))
            ->when(($filtros['tipo_aplicacion'] ?? 'todos') === 'general', fn (Builder $q) => $q->whereNull('abonos_clientes.venta_id'))
            ->when($this->texto($filtros), fn (Builder $q, string $like) => $this->buscarAbono($q, $like, trim((string) $filtros['q'])));
        $estado = $filtros['estado'] ?? 'vigentes';
        $conEstado = fn (Builder $q) => $q
            ->when($estado === 'vigentes', fn (Builder $w) => $w->whereDoesntHave('anulacion'))
            ->when($estado === 'anulados', fn (Builder $w) => $w->whereHas('anulacion'));

        $porCliente = ($filtros['vista'] ?? 'detalle') === 'por_cliente';
        $pagina = $porCliente
            ? $this->recuperadoPorCliente($base()->tap($conEstado), $filtros['orden'] ?? 'valor_desc', $porPagina)
            : $this->recuperadoDetalle($base()->tap($conEstado), $filtros['orden'] ?? 'fecha_desc', $porPagina);

        return [...$pagina, 'totales' => $this->totalesRecuperados($base())];
    }

    /** q: nombre o documento del cliente, número de la factura o remisión, #venta, referencia o nota. */
    private function buscarAbono(Builder $q, string $like, string $texto): void
    {
        $digitos = preg_replace('/\D/', '', $texto);
        $q->where(fn (Builder $w) => $w
            ->where('abonos_clientes.referencia', 'like', $like)
            ->orWhere('abonos_clientes.nota', 'like', $like)
            ->orWhereHas('cliente', fn (Builder $c) => $c->where('nombre', 'like', $like)
                ->orWhere('documento', 'like', $like)
                ->when($digitos !== '', fn (Builder $d) => $d->orWhereRaw("REPLACE(REPLACE(REPLACE(documento, '.', ''), '-', ''), ' ', '') like ?", ['%'.$digitos.'%'])))
            ->orWhereHas('venta', fn (Builder $v) => $v->where('factura_contapyme', 'like', $like))
            ->when(ctype_digit(ltrim($texto, '#')), fn (Builder $o) => $o->orWhere('abonos_clientes.venta_id', (int) ltrim($texto, '#'))));
    }

    /** @return array<string, mixed> */
    private function recuperadoDetalle(Builder $q, string $orden, int $porPagina): array
    {
        $pagina = $q->with(['cliente:id,nombre,documento', 'cuenta:id,nombre,tipo', 'venta:id,factura_contapyme,tipo_documento', 'usuario:id,name', 'anulacion.usuario:id,name'])
            ->when($orden === 'valor_desc', fn (Builder $o) => $o->orderByDesc('abonos_clientes.valor'), fn (Builder $o) => $o->orderByDesc('abonos_clientes.fecha'))
            ->orderByDesc('abonos_clientes.id')
            ->paginate($porPagina);

        return [
            ...$pagina->toArray(),
            'data' => $pagina->getCollection()->map(fn (AbonoCliente $a) => [
                'id' => $a->id,
                'fecha' => $a->fecha?->toDateString(),
                'cliente' => $a->cliente ? ['id' => $a->cliente->id, 'nombre' => $a->cliente->nombre, 'documento' => $a->cliente->documento] : null,
                'valor' => bcadd((string) $a->valor, '0', self::ESCALA),
                'cuenta' => $a->cuenta ? ['id' => $a->cuenta->id, 'nombre' => $a->cuenta->nombre, 'tipo' => $a->cuenta->tipo] : null,
                'referencia' => $a->referencia,
                'nota' => $a->nota,
                // «General»: sin venta (abonos FIFO anteriores a S-79).
                'documento' => $a->venta_id === null ? null : [
                    'venta_id' => $a->venta_id,
                    'tipo' => $a->venta?->tipo_documento?->value ?? ($a->venta?->factura_contapyme ? 'factura' : null),
                    'numero' => $a->venta?->factura_contapyme,
                ],
                'aplicado_a' => $a->aplicadoA(),
                'usuario' => $a->usuario ? ['id' => $a->usuario->id, 'name' => $a->usuario->name] : null,
                'anulado' => $a->anulacion !== null,
                'anulacion' => $a->anulacion ? [
                    'motivo' => $a->anulacion->motivo,
                    'created_at' => $a->anulacion->created_at,
                    'usuario' => $a->anulacion->usuario ? ['id' => $a->anulacion->usuario->id, 'name' => $a->anulacion->usuario->name] : null,
                ] : null,
            ])->values()->all(),
        ];
    }

    /** Una fila por cliente (agrupada en SQL) y su saldo actual en una sola consulta para la página. */
    private function recuperadoPorCliente(Builder $q, string $orden, int $porPagina): array
    {
        $pagina = $q->toBase()
            ->join('clientes as cl', 'cl.id', '=', 'abonos_clientes.cliente_id')
            ->leftJoin('abono_anulaciones as an', 'an.abono_id', '=', 'abonos_clientes.id')
            ->groupBy('abonos_clientes.cliente_id', 'cl.nombre', 'cl.documento')
            ->selectRaw('abonos_clientes.cliente_id AS id, cl.nombre, cl.documento')
            ->selectRaw('SUM(CASE WHEN an.id IS NULL THEN 1 ELSE 0 END) AS abonos')
            ->selectRaw('COALESCE(SUM(CASE WHEN an.id IS NULL THEN abonos_clientes.valor END), 0) AS recuperado')
            ->selectRaw('MAX(abonos_clientes.fecha) AS ultimo_abono')
            ->when($orden === 'nombre', fn ($o) => $o->orderBy('cl.nombre'), fn ($o) => $o->orderByDesc('recuperado'))
            ->orderBy('abonos_clientes.cliente_id')
            ->paginate($porPagina);

        $saldos = $this->cartera->saldosClientes($pagina->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all(), Carbon::today()->toDateString());

        return [
            ...$pagina->toArray(),
            'data' => $pagina->getCollection()->map(fn ($f) => [
                'cliente' => ['id' => (int) $f->id, 'nombre' => $f->nombre, 'documento' => $f->documento],
                'abonos' => (int) $f->abonos,
                'recuperado' => bcadd((string) $f->recuperado, '0', self::ESCALA),
                'ultimo_abono' => substr((string) $f->ultimo_abono, 0, 10),
                'saldo_actual' => $saldos[(int) $f->id] ?? '0.00',
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function totalesRecuperados(Builder $q): array
    {
        $filas = $q->toBase()
            ->leftJoin('abono_anulaciones as an', 'an.abono_id', '=', 'abonos_clientes.id')
            ->leftJoin('cuentas_pago as cp', 'cp.id', '=', 'abonos_clientes.cuenta_pago_id')
            ->selectRaw('abonos_clientes.cuenta_pago_id AS cuenta_id, GROUPING(abonos_clientes.cuenta_pago_id) AS es_total')
            ->selectRaw('MAX(cp.nombre) AS cuenta, MAX(cp.tipo) AS tipo')
            ->selectRaw('COALESCE(SUM(CASE WHEN an.id IS NULL THEN abonos_clientes.valor END), 0) AS recuperado')
            ->selectRaw('SUM(CASE WHEN an.id IS NULL THEN 1 ELSE 0 END) AS cantidad')
            ->selectRaw('COUNT(DISTINCT CASE WHEN an.id IS NULL THEN abonos_clientes.cliente_id END) AS clientes')
            ->selectRaw('COALESCE(SUM(CASE WHEN an.id IS NOT NULL THEN abonos_clientes.valor END), 0) AS anulado')
            ->selectRaw('SUM(CASE WHEN an.id IS NOT NULL THEN 1 ELSE 0 END) AS cantidad_anulados')
            ->groupByRaw('abonos_clientes.cuenta_pago_id WITH ROLLUP')
            ->get();

        $total = $filas->firstWhere('es_total', 1);
        $pesos = fn ($v) => bcadd((string) ($v ?? 0), '0', self::ESCALA);

        return [
            'total_recuperado' => $pesos($total?->recuperado),
            'cantidad_abonos' => (int) ($total?->cantidad ?? 0),
            'clientes' => (int) ($total?->clientes ?? 0),
            'total_anulado' => $pesos($total?->anulado),
            'cantidad_anulados' => (int) ($total?->cantidad_anulados ?? 0),
            // S-48: un abono anterior sin cuenta entró en efectivo; se muestra como "Sin cuenta (anterior)".
            'por_cuenta' => $filas->where('es_total', 0)->filter(fn ($f) => (int) $f->cantidad > 0)
                ->map(fn ($f) => [
                    'cuenta_id' => $f->cuenta_id === null ? null : (int) $f->cuenta_id,
                    'cuenta' => $f->cuenta ?? 'Sin cuenta (anterior)',
                    'tipo' => $f->tipo ?? 'efectivo',
                    'total' => $pesos($f->recuperado),
                    'cantidad' => (int) $f->cantidad,
                ])->sortByDesc(fn ($c) => (float) $c['total'])->values()->all(),
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
