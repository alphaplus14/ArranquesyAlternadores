<?php

namespace App\Services;

use App\Enums\EstadoVenta;
use App\Models\Configuracion;
use App\Support\CostoEfectivo;
use App\Support\RangoFechas;
use App\Support\TotalesIngreso;
use App\Support\TotalesVenta;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * RF-23 / S-45: informes de gestión del administrador. Solo lee; nada se exporta (regla 16).
 *
 * - Ventas: cerradas, netas de reversos (ítems sin reverso_items), por fecha de cierre (`cerrada_at`)
 *   en días de Bogotá (RangoFechas), con IVA incluido (S-33). Base e IVA con TotalesVenta. Las
 *   abiertas no cuentan hasta cerrarse.
 * - Costo de una unidad: CostoEfectivo (base de su línea de ingreso ÷ cantidad, de TotalesIngreso).
 * - Margen estimado = base de los ítems vendidos con costo conocido − su costo. Margen % sobre esa
 *   misma base. `cobertura_costo` = % de las unidades vendidas con costo. No es contabilidad.
 *
 * Todo con consultas agregadas en SQL (GROUP BY por clave, IVA y línea de ingreso) y el cálculo
 * de dinero en bcmath sobre los agregados, sin traer ítem por ítem. Cada informe queda en caché
 * 5 minutos por combinación de filtros (config/panel.php).
 */
class PanelService
{
    private const ESCALA = 2;

    /** Costos por línea ya calculados en esta petición (varios informes comparten líneas). */
    private array $costos = [];

    public function __construct(
        private readonly StockMinimoService $stockMinimo,
        private readonly ReporteService $reportes,
    ) {}

    /* ───────────────────────── Configuración (S-45 e) ───────────────────────── */

    public function diasSinMovimiento(): int
    {
        $valor = Configuracion::where('clave', Configuracion::DIAS_SIN_MOVIMIENTO)->value('valor');

        return $valor === null ? (int) config('panel.sin_movimiento.dias_por_defecto') : (int) $valor;
    }

    /** Solo el admin (lo protege la ruta). Queda auditado por Auditable (RF-11) con el valor anterior. */
    public function fijarDiasSinMovimiento(int $dias): int
    {
        Configuracion::firstOrCreate(
            ['clave' => Configuracion::DIAS_SIN_MOVIMIENTO],
            ['valor' => (string) config('panel.sin_movimiento.dias_por_defecto')],
        )->update(['valor' => (string) $dias]);

        return $dias;
    }

    /* ───────────────────────── Caché (S-45 g) ───────────────────────── */

    /**
     * La clave incluye el informe, los filtros y el valor de "días sin movimiento": cambiarlo en
     * Configuración se refleja de inmediato. `refrescar` recalcula y vuelve a guardar.
     */
    public function enCache(string $informe, array $parametros, bool $refrescar, Closure $calcular): array
    {
        $clave = 'panel:'.$informe.':'.md5(json_encode([$parametros, $this->diasSinMovimiento()]));
        if ($refrescar) {
            Cache::forget($clave);
        }

        return Cache::remember($clave, (int) config('panel.cache_segundos'), fn () => [
            'generado_at' => now()->toIso8601String(),
            ...$calcular(),
        ]);
    }

    /* ───────────────────────── Informes ───────────────────────── */

    /** Resumen: ventas, margen, inventario, cartera, cuentas por pagar, compras, agotados y bajo mínimo. */
    public function resumen(array $f): array
    {
        $anterior = $this->periodoAnterior($f);
        $actual = $this->ventasDe($f);
        $previo = $this->ventasDe($anterior);
        $corte = Carbon::parse(RangoFechas::hoy());

        $cartera = $this->reportes->carteraClientes($corte)['totales'];
        $porPagar = $this->reportes->cuentasPorPagar($corte)['totales'];
        $compras = $this->reportes->compras(Carbon::parse($f['desde']), Carbon::parse($f['hasta']), $f['proveedor_id'])['totales'];

        return [
            ...$this->rango($f),
            'periodo_anterior' => ['desde' => $anterior['desde'], 'hasta' => $anterior['hasta']],
            'ventas' => [
                ...$actual['ventas'],
                'anterior' => $previo['ventas'],
                'variacion_total' => $this->variacion($actual['ventas']['total'], $previo['ventas']['total']),
            ],
            'margen' => [
                ...$actual['margen'],
                'anterior' => $previo['margen'],
                'variacion' => $actual['margen']['margen'] === null || $previo['margen']['margen'] === null
                    ? null : $this->variacion($actual['margen']['margen'], $previo['margen']['margen']),
            ],
            'inventario' => $this->inventario($f),
            // Cartera y cuentas por pagar no se filtran por categoría ni proveedor.
            'cartera' => ['por_cobrar' => $cartera['saldo'], 'vencida' => $cartera['vencido']],
            'cuentas_por_pagar' => ['por_pagar' => $porPagar['saldo'], 'vencidas' => $porPagar['vencido']],
            'compras' => ['total' => $compras['total'] ?? '0.00'],
            'agotados' => $this->referencias($f)->where('r.activo', true)->where('r.existencia', 0)->count(),
            'bajo_minimo' => $this->stockMinimo->bajoMinimo(array_filter(['categoria_id' => $f['categoria_id'], 'proveedor_id' => $f['proveedor_id']]), 1)->total(),
        ];
    }

    /** Serie diaria (día local de Bogotá) con contado, crédito y unidades; días sin ventas en 0. */
    public function ventasPorDia(array $f): array
    {
        $serie = $this->serieDiaria($f);

        return [
            ...$this->rango($f),
            'serie' => array_values($serie),
            'totales' => [
                'contado' => $this->sumarCampo($serie, 'contado'),
                'credito' => $this->sumarCampo($serie, 'credito'),
                'total' => $this->sumarCampo($serie, 'total'),
                'unidades' => array_sum(array_column($serie, 'unidades')),
                'ventas' => array_sum(array_column($serie, 'ventas')),
            ],
        ];
    }

    /** Más vendidos por referencia; orden por unidades, valor o margen. ABC por valor de venta. */
    public function masVendidos(array $f, string $orden, int $limite): array
    {
        $grupos = $this->agregarVentas($this->vendidos($f), 'r.id');
        $totalValor = array_reduce($grupos, fn ($s, $g) => bcadd($s, $g['total'], self::ESCALA), '0');
        $clases = $this->clasesAbc($grupos, $totalValor);
        $info = $this->infoReferencias(array_keys($grupos));

        $filas = collect($grupos)->map(fn ($g, $id) => [
            'referencia' => $info[$id] ?? ['id' => (int) $id],
            'unidades' => $g['unidades'],
            'valor' => $g['total'],
            'base' => $g['base'],
            'margen' => $g['margen'],
            'margen_porcentaje' => $g['margen_porcentaje'],
            'unidades_sin_costo' => $g['unidades_sin_costo'],
            'porcentaje_total' => $this->porcentaje($g['total'], $totalValor),
            'clase' => $orden === 'valor' ? $clases[$id] : null,
        ]);

        $filas = match ($orden) {
            'valor' => $filas->sortBy([['valor', 'desc'], ['unidades', 'desc']], SORT_NUMERIC),
            // Sin costo conocido (margen null) va al final, nunca como 0.
            'margen' => $filas->sortBy([fn ($a, $b) => [$b['margen'] !== null, (float) $b['margen']] <=> [$a['margen'] !== null, (float) $a['margen']]]),
            default => $filas->sortBy([['unidades', 'desc'], ['valor', 'desc']], SORT_NUMERIC),
        };

        return [
            ...$this->rango($f),
            'orden' => $orden,
            'total_valor' => $totalValor,
            'filas' => $filas->take($limite)->values()->all(),
        ];
    }

    /** Reabastecer: vendió >= min en el período y (agotada, cobertura corta o bajo mínimo efectivo). */
    public function reabastecer(array $f): array
    {
        $dias = $this->diasDe($f);
        $min = (int) config('panel.reabastecer.min_vendidas');
        $vendidas = $this->vendidos($f)
            ->selectRaw('r.id, COUNT(*) as n')
            ->groupBy('r.id')
            ->havingRaw('COUNT(*) >= ?', [$min])
            ->pluck('n', 'id');

        $filas = $vendidas->isEmpty() ? collect() : DB::table('referencias as r')
            ->join('proveedores as p', 'p.id', '=', 'r.proveedor_id')
            ->leftJoin('codigos_universales as cu', 'cu.id', '=', 'r.codigo_universal_id')
            ->whereIn('r.id', $vendidas->keys())
            ->where('r.activo', true)
            ->select([
                'r.id', 'r.codigo_referencia', 'r.descripcion', 'r.calidad', 'r.existencia', 'r.stock_minimo',
                'r.codigo_universal_id', 'cu.codigo as universal', 'cu.stock_minimo as minimo_universal',
                'p.id as proveedor_id', 'p.nombre as proveedor',
            ])
            ->selectSub(fn (Builder $q) => $q->from('referencias as ru')->selectRaw('COALESCE(SUM(ru.existencia), 0)')
                ->whereColumn('ru.codigo_universal_id', 'r.codigo_universal_id')->where('ru.activo', true), 'existencia_universal')
            ->get();

        $cobertura = (float) config('panel.reabastecer.cobertura_dias');
        $lista = $filas->map(function ($r) use ($vendidas, $dias, $cobertura) {
            $n = (int) $vendidas[$r->id];
            $existencia = (int) $r->existencia;
            $diasCobertura = $existencia === 0 ? 0.0 : round($existencia / ($n / $dias), 1);
            $esUniversal = $r->codigo_universal_id !== null;
            $minimo = $this->stockMinimo->efectivo($esUniversal ? ($r->minimo_universal === null ? null : (int) $r->minimo_universal) : ($r->stock_minimo === null ? null : (int) $r->stock_minimo))['minimo'];
            $evaluada = $esUniversal ? (int) $r->existencia_universal : $existencia;
            $bajo = $minimo !== null && $evaluada <= $minimo;

            $motivo = match (true) {
                $existencia === 0 => 'agotada',
                $bajo => 'bajo_minimo',
                $diasCobertura < $cobertura => 'cobertura_corta',
                default => null,
            };

            return $motivo === null ? null : [
                'referencia' => $this->filaReferencia($r),
                'vendidas' => $n,
                'existencia' => $existencia,
                'cobertura_dias' => $diasCobertura,
                'minimo_efectivo' => $minimo,
                'motivo' => $motivo,
            ];
        })->filter()->sortBy([['existencia', 'asc'], ['cobertura_dias', 'asc'], ['vendidas', 'desc']])->values();

        return [...$this->rango($f), 'total' => $lista->count(), 'filas' => $lista->take((int) config('panel.reabastecer.limite'))->all()];
    }

    /**
     * Stock estancado: referencia activa con existencia > 0 sin venta neta cerrada en los últimos
     * `dias`. Nunca vendida: se cuenta desde su unidad disponible más antigua. Ingresos y ajustes no
     * son movimiento. Marca si otra referencia de su universal se vendió en esos días (RF-02).
     */
    public function sinMovimiento(array $f, int $dias, int $pagina): array
    {
        $limite = Carbon::now()->subDays($dias);
        $porPagina = (int) config('panel.sin_movimiento.por_pagina');

        $ultimas = DB::table('venta_items as vi')
            ->join('ventas as v', 'v.id', '=', 'vi.venta_id')
            ->join('unidades as u', 'u.id', '=', 'vi.unidad_id')
            ->leftJoin('reverso_items as ri', 'ri.venta_item_id', '=', 'vi.id')
            ->where('v.estado', EstadoVenta::Cerrada->value)
            ->whereNull('ri.id')
            ->selectRaw('u.referencia_id, MAX(v.cerrada_at) as ultima')
            ->groupBy('u.referencia_id');
        $stock = DB::table('unidades')
            ->where('estado', 'disponible')
            ->selectRaw('referencia_id, MIN(created_at) as desde, COUNT(*) as n')
            ->groupBy('referencia_id');

        $base = $this->referencias($f)
            ->joinSub($stock, 's', 's.referencia_id', '=', 'r.id')
            ->leftJoinSub($ultimas, 'uv', 'uv.referencia_id', '=', 'r.id')
            ->where('r.activo', true)
            ->where('r.existencia', '>', 0)
            ->whereRaw('COALESCE(uv.ultima, s.desde) < ?', [$limite]);

        $totales = $base->clone()->selectRaw('COUNT(*) as referencias, COALESCE(SUM(s.n), 0) as unidades')->first();
        $costoTotal = $this->costoDeDisponibles($base->clone()->select('r.id'));

        $paginador = $base->clone()
            ->join('proveedores as p', 'p.id', '=', 'r.proveedor_id')
            ->join('categorias as c', 'c.id', '=', 'r.categoria_id')
            ->leftJoin('codigos_universales as cu', 'cu.id', '=', 'r.codigo_universal_id')
            ->select([
                'r.id', 'r.codigo_referencia', 'r.descripcion', 'r.calidad', 'r.existencia', 'r.codigo_universal_id',
                'cu.codigo as universal', 'p.id as proveedor_id', 'p.nombre as proveedor', 'c.id as categoria_id', 'c.nombre as categoria',
                'uv.ultima', 's.desde',
            ])
            ->orderByRaw('COALESCE(uv.ultima, s.desde)')
            ->orderBy('r.id')
            ->paginate($porPagina, ['*'], 'page', $pagina);

        $filas = collect($paginador->items());
        $ids = $filas->pluck('id')->all();
        $costos = $this->costoPorReferencia($ids);
        $rotan = $this->universalesQueRotan($filas->pluck('codigo_universal_id')->filter()->unique()->all(), $limite);
        $ahora = Carbon::now();

        return [
            'dias' => $dias,
            'desde_limite' => RangoFechas::diaLocal($limite),
            'totales' => [
                'referencias' => (int) $totales->referencias,
                'unidades' => (int) $totales->unidades,
                'valor_costo' => $costoTotal['costo'],
                'unidades_sin_costo' => $costoTotal['sin_costo'],
            ],
            'data' => $filas->map(fn ($r) => [
                'referencia' => $this->filaReferencia($r),
                'existencia' => (int) $r->existencia,
                'valor_costo' => $costos[$r->id]['costo'] ?? '0.00',
                'unidades_sin_costo' => $costos[$r->id]['sin_costo'] ?? 0,
                'ultima_venta' => $r->ultima ? RangoFechas::diaLocal($r->ultima) : null,
                'en_stock_desde' => RangoFechas::diaLocal($r->desde),
                'dias_sin_vender' => (int) Carbon::parse($r->ultima ?? $r->desde)->diffInDays($ahora),
                'universal_rota' => $r->codigo_universal_id === null ? null : in_array((int) $r->codigo_universal_id, $rotan, true),
            ])->all(),
            'current_page' => $paginador->currentPage(),
            'last_page' => $paginador->lastPage(),
            'per_page' => $paginador->perPage(),
            'total' => $paginador->total(),
        ];
    }

    /** Antigüedad de las unidades disponibles (por la fecha de la unidad): unidades, valor a costo y referencias. */
    public function antiguedadStock(array $f): array
    {
        [$caso, $enlaces] = $this->casoAntiguedad();
        $disponibles = $this->referencias($f)->join('unidades as u', 'u.referencia_id', '=', 'r.id')->where('u.estado', 'disponible');

        $porLinea = $disponibles->clone()
            ->selectRaw("{$caso} as rango, u.ingreso_item_id as linea, COUNT(*) as n", $enlaces)
            // Por el alias: el CASE lleva parámetros y MySQL no lo reconoce igual en SELECT y GROUP BY.
            ->groupBy('rango', 'u.ingreso_item_id')
            ->get()->groupBy('rango');
        $refs = $disponibles->clone()
            ->selectRaw("{$caso} as rango, COUNT(DISTINCT u.referencia_id) as refs", $enlaces)
            ->groupBy('rango')
            ->pluck('refs', 'rango');
        $costos = $this->costosDe($porLinea->flatten()->pluck('linea'));

        $rangos = [];
        foreach (array_keys(config('panel.antiguedad')) as $rango) {
            $filas = $porLinea->get($rango, collect());
            $costo = CostoEfectivo::sumar($filas->map(fn ($x) => ['linea' => $x->linea === null ? null : (int) $x->linea, 'unidades' => (int) $x->n]), $costos);
            $rangos[] = [
                'rango' => $rango,
                'unidades' => (int) $filas->sum('n'),
                'valor_costo' => $costo['costo'],
                'unidades_sin_costo' => $costo['sin_costo'],
                'referencias' => (int) ($refs[$rango] ?? 0),
            ];
        }

        return ['rangos' => $rangos];
    }

    /** Rotación por categoría o proveedor: ventas, margen, existencia, valor a costo y cobertura. */
    public function rotacion(array $f, string $agrupar): array
    {
        $columna = $agrupar === 'proveedor' ? 'r.proveedor_id' : 'r.categoria_id';
        $dias = $this->diasDe($f);
        $ventas = $this->agregarVentas($this->vendidos($f), $columna);

        $stock = $this->referencias($f)->join('unidades as u', 'u.referencia_id', '=', 'r.id')->where('u.estado', 'disponible')
            ->selectRaw("{$columna} as clave, u.ingreso_item_id as linea, COUNT(*) as n")
            ->groupByRaw("{$columna}, u.ingreso_item_id")
            ->get()->groupBy('clave');
        $costos = $this->costosDe($stock->flatten()->pluck('linea'));

        $compras = $agrupar === 'proveedor'
            ? collect($this->reportes->compras(Carbon::parse($f['desde']), Carbon::parse($f['hasta']), $f['proveedor_id'])['filas'])
                ->groupBy(fn ($c) => $c['proveedor']['id'])
                ->map(fn ($g) => $g->reduce(fn ($s, $c) => bcadd($s, $c['total'], self::ESCALA), '0.00'))
            : collect();

        $claves = collect(array_keys($ventas))->merge($stock->keys())->merge($compras->keys())->unique()->filter()->values();
        $nombres = DB::table($agrupar === 'proveedor' ? 'proveedores' : 'categorias')->whereIn('id', $claves)->pluck('nombre', 'id');
        $sobrestock = (float) config('panel.sobrestock_dias');

        $filas = $claves->map(function ($clave) use ($ventas, $stock, $costos, $compras, $dias, $sobrestock, $nombres, $agrupar) {
            $v = $ventas[$clave] ?? null;
            $s = $stock->get($clave, collect());
            $costo = CostoEfectivo::sumar($s->map(fn ($x) => ['linea' => $x->linea === null ? null : (int) $x->linea, 'unidades' => (int) $x->n]), $costos);
            $existencia = (int) $s->sum('n');
            $vendidas = $v['unidades'] ?? 0;
            $cobertura = $vendidas > 0 ? round($existencia / ($vendidas / $dias), 1) : null;

            return [
                'id' => (int) $clave,
                'nombre' => $nombres[$clave] ?? '—',
                'unidades_vendidas' => $vendidas,
                'ventas' => $v['total'] ?? '0.00',
                'margen' => $v['margen'] ?? null,
                'margen_porcentaje' => $v['margen_porcentaje'] ?? null,
                'existencia' => $existencia,
                'valor_costo' => $costo['costo'],
                'unidades_sin_costo' => $costo['sin_costo'],
                // null = sin rotación (no se vendió en el período).
                'cobertura_dias' => $cobertura,
                'sobrestock' => $cobertura !== null && $cobertura > $sobrestock,
                'compras' => $agrupar === 'proveedor' ? ($compras[$clave] ?? '0.00') : null,
            ];
        })->sortBy([['ventas', 'desc'], ['existencia', 'desc']], SORT_NUMERIC)->values();

        return [...$this->rango($f), 'agrupar' => $agrupar, 'filas' => $filas->all()];
    }

    /** Control: ajustes, reversos, % digitado, ventas por usuario, abiertas viejas, cambios de precio y días sin ventas. */
    public function control(array $f): array
    {
        [$ini, $fin] = $this->limites($f);

        $ajustes = $this->referencias($f)
            ->join('unidades as u', 'u.referencia_id', '=', 'r.id')
            ->join('ajuste_items as ai', 'ai.unidad_id', '=', 'u.id')
            ->join('ajustes_inventario as a', 'a.id', '=', 'ai.ajuste_id')
            ->where('a.created_at', '>=', $ini)->where('a.created_at', '<', $fin)
            ->selectRaw('a.tipo, a.motivo, u.ingreso_item_id as linea, COUNT(*) as n')
            ->groupBy('a.tipo', 'a.motivo', 'u.ingreso_item_id')
            ->get();
        $costos = $this->costosDe($ajustes->pluck('linea'));
        $porMotivo = $ajustes->groupBy(fn ($x) => $x->tipo.'|'.$x->motivo)->map(function ($g) use ($costos) {
            $c = CostoEfectivo::sumar($g->map(fn ($x) => ['linea' => $x->linea === null ? null : (int) $x->linea, 'unidades' => (int) $x->n]), $costos);

            return [
                'tipo' => $g->first()->tipo,
                'motivo' => $g->first()->motivo,
                'unidades' => (int) $g->sum('n'),
                'valor_costo' => $c['costo'],
                'unidades_sin_costo' => $c['sin_costo'],
            ];
        })->sortBy([['tipo', 'asc'], ['unidades', 'desc']])->values();

        $reversos = $this->referencias($f)
            ->join('unidades as u', 'u.referencia_id', '=', 'r.id')
            ->join('venta_items as vi', 'vi.unidad_id', '=', 'u.id')
            ->join('reverso_items as ri', 'ri.venta_item_id', '=', 'vi.id')
            ->join('reversos as rv', 'rv.id', '=', 'ri.reverso_id')
            ->where('rv.created_at', '>=', $ini)->where('rv.created_at', '<', $fin)
            ->selectRaw('rv.tipo, COUNT(DISTINCT rv.id) as reversos, COUNT(*) as items, COALESCE(SUM(vi.precio_unitario), 0) as valor')
            ->groupBy('rv.tipo')
            ->get()->keyBy('tipo');

        $captura = $this->vendidos($f)
            ->selectRaw("COUNT(*) as total, COALESCE(SUM(vi.metodo_captura = 'digitado'), 0) as digitadas")
            ->first();

        $porUsuario = $this->vendidos($f)
            ->join('users as us', 'us.id', '=', 'v.usuario_id')
            ->selectRaw('us.id, us.name, COUNT(DISTINCT v.id) as ventas, COUNT(*) as unidades, SUM(vi.precio_unitario) as total')
            ->groupBy('us.id', 'us.name')
            ->get();
        // S-73: las ventas a crédito de solo valor cuentan como venta del usuario (sin unidades).
        $soloValorPorUsuario = $this->creditosSoloValor($f)
            ->join('users as us', 'us.id', '=', 'v.usuario_id')
            ->selectRaw('us.id, us.name, COUNT(*) as ventas, 0 as unidades, SUM(v.valor_total) as total')
            ->groupBy('us.id', 'us.name')
            ->get();
        $porUsuario = $porUsuario->concat($soloValorPorUsuario)
            ->groupBy('id')
            ->map(fn ($g) => (object) [
                'id' => $g->first()->id,
                'name' => $g->first()->name,
                'ventas' => $g->sum(fn ($x) => (int) $x->ventas),
                'unidades' => $g->sum(fn ($x) => (int) $x->unidades),
                'total' => $g->reduce(fn ($t, $x) => bcadd($t, (string) $x->total, self::ESCALA), '0'),
            ])
            ->sortByDesc(fn ($u) => (float) $u->total)
            ->values();

        // Estado actual (no depende del período ni de los filtros).
        // S-46: solo las abiertas CON ítems vigentes (las vacías se descartan solas y no avisan).
        $abiertasViejas = DB::table('ventas')
            ->where('estado', EstadoVenta::Abierta->value)
            ->where('created_at', '<', RangoFechas::inicio(RangoFechas::hoy()))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('venta_items as vi')
                ->leftJoin('reverso_items as ri', 'ri.venta_item_id', '=', 'vi.id')
                ->whereColumn('vi.venta_id', 'ventas.id')->whereNull('ri.id'))
            ->count();
        $cambiosPrecio = DB::table('auditoria')
            ->where('tabla', 'venta_items')->where('campo', 'precio_unitario')
            ->where('created_at', '>=', $ini)->where('created_at', '<', $fin)
            ->count();
        $serie = $this->serieDiaria($f);

        $reverso = fn (string $tipo) => [
            'reversos' => (int) ($reversos[$tipo]->reversos ?? 0),
            'items' => (int) ($reversos[$tipo]->items ?? 0),
            'valor' => bcadd((string) ($reversos[$tipo]->valor ?? '0'), '0', self::ESCALA),
        ];

        return [
            ...$this->rango($f),
            'ajustes' => $porMotivo->all(),
            'reversos' => ['devolucion' => $reverso('devolucion'), 'anulacion' => $reverso('anulacion')],
            'capturas' => [
                'total' => (int) $captura->total,
                'digitadas' => (int) $captura->digitadas,
                'porcentaje_digitado' => (int) $captura->total > 0 ? round($captura->digitadas / $captura->total * 100, 1) : null,
            ],
            'ventas_por_usuario' => $porUsuario->map(fn ($u) => [
                'usuario' => ['id' => (int) $u->id, 'nombre' => $u->name],
                'ventas' => (int) $u->ventas,
                'unidades' => (int) $u->unidades,
                'total' => bcadd((string) $u->total, '0', self::ESCALA),
            ])->all(),
            'ventas_abiertas_viejas' => $abiertasViejas,
            'cambios_precio_credito' => $cambiosPrecio,
            'dias_sin_ventas' => count(array_filter($serie, fn ($d) => $d['ventas'] === 0)),
            'dias' => count($serie),
        ];
    }

    /* ───────────────────────── Piezas compartidas ───────────────────────── */

    /** Ítems vendidos netos: venta cerrada en el período, sin reverso, con precio; filtros de referencia. */
    private function vendidos(array $f): Builder
    {
        [$ini, $fin] = $this->limites($f);

        return DB::table('venta_items as vi')
            ->join('ventas as v', 'v.id', '=', 'vi.venta_id')
            ->join('unidades as u', 'u.id', '=', 'vi.unidad_id')
            ->join('referencias as r', 'r.id', '=', 'u.referencia_id')
            ->leftJoin('reverso_items as ri', 'ri.venta_item_id', '=', 'vi.id')
            ->where('v.estado', EstadoVenta::Cerrada->value)
            ->where('v.cerrada_at', '>=', $ini)
            ->where('v.cerrada_at', '<', $fin)
            ->whereNull('ri.id')
            ->whereNotNull('vi.precio_unitario')
            ->when($f['categoria_id'], fn ($q, $id) => $q->where('r.categoria_id', $id))
            ->when($f['proveedor_id'], fn ($q, $id) => $q->where('r.proveedor_id', $id));
    }

    private function referencias(array $f): Builder
    {
        return DB::table('referencias as r')
            ->when($f['categoria_id'], fn ($q, $id) => $q->where('r.categoria_id', $id))
            ->when($f['proveedor_id'], fn ($q, $id) => $q->where('r.proveedor_id', $id));
    }

    /**
     * Agrega las ventas netas por una clave SQL. Se agrupa por (clave, IVA, línea de ingreso): la
     * base de Σ precios con el mismo IVA es exacta (TotalesVenta la calcula sobre la suma) y el
     * costo sale por línea (CostoEfectivo).
     *
     * @return array<int|string, array{unidades:int, total:string, base:string, base_con_costo:string, costo:string, unidades_con_costo:int, unidades_sin_costo:int, margen:?string, margen_porcentaje:?float}>
     */
    private function agregarVentas(Builder $consulta, string $clave): array
    {
        $filas = $consulta->clone()
            ->selectRaw("{$clave} as clave, vi.iva_porcentaje as iva, u.ingreso_item_id as linea, COUNT(*) as n, SUM(vi.precio_unitario) as precio")
            ->groupByRaw("{$clave}, vi.iva_porcentaje, u.ingreso_item_id")
            ->get();
        $costos = $this->costosDe($filas->pluck('linea'));

        $grupos = [];
        foreach ($filas->groupBy('clave') as $valor => $g) {
            $conCosto = $g->filter(fn ($x) => $x->linea !== null && isset($costos[(int) $x->linea]));
            $todo = TotalesVenta::desglosar($g->map(fn ($x) => ['precio' => $x->precio, 'iva' => $x->iva, 'vigente' => true]));
            $baseCon = TotalesVenta::desglosar($conCosto->map(fn ($x) => ['precio' => $x->precio, 'iva' => $x->iva, 'vigente' => true]))->base;
            $c = CostoEfectivo::sumar($g->map(fn ($x) => ['linea' => $x->linea === null ? null : (int) $x->linea, 'unidades' => (int) $x->n]), $costos);
            $margen = $c['con_costo'] > 0 ? bcsub($baseCon, $c['costo'], self::ESCALA) : null;

            $grupos[$valor] = [
                'unidades' => (int) $g->sum('n'),
                'total' => $todo->total,
                'base' => $todo->base,
                'base_con_costo' => $baseCon,
                'costo' => $c['costo'],
                'unidades_con_costo' => $c['con_costo'],
                'unidades_sin_costo' => $c['sin_costo'],
                'margen' => $margen,
                'margen_porcentaje' => $margen !== null && bccomp($baseCon, '0', self::ESCALA) > 0
                    ? round((float) bcdiv(bcmul($margen, '100', 6), $baseCon, 6), 1) : null,
            ];
        }

        return $grupos;
    }

    /** Ventas, margen y cobertura de costo de un período (para el resumen y su período anterior). */
    private function ventasDe(array $f): array
    {
        $g = $this->agregarVentas($this->vendidos($f), "'todo'")['todo'] ?? null;
        $porForma = $this->vendidos($f)
            ->selectRaw('v.forma_pago, COUNT(DISTINCT v.id) as ventas, SUM(vi.precio_unitario) as total')
            ->groupBy('v.forma_pago')
            ->get()->keyBy('forma_pago');

        // S-73: ventas a crédito de solo valor (sin ítems): suman al total, al crédito, al conteo y al
        // ticket; no a unidades, base, IVA ni margen (no tienen productos ni costo).
        $sv = $this->creditosSoloValor($f)->selectRaw('COUNT(*) as ventas, COALESCE(SUM(v.valor_total), 0) as total')->first();
        $totalItems = $g['total'] ?? '0.00';
        $total = bcadd($totalItems, (string) $sv->total, self::ESCALA);
        $ventas = (int) $porForma->sum('ventas') + (int) $sv->ventas;
        $unidades = $g['unidades'] ?? 0;

        return [
            'ventas' => [
                'total' => $total,
                'base' => $g['base'] ?? '0.00',
                'iva_incluido' => bcsub($totalItems, $g['base'] ?? '0.00', self::ESCALA),
                'ventas' => $ventas,
                'unidades' => $unidades,
                'ticket_promedio' => $ventas > 0 ? TotalesIngreso::redondearAlPeso(bcdiv($total, (string) $ventas, 10)) : '0.00',
                'contado' => bcadd((string) ($porForma['contado']->total ?? '0'), '0', self::ESCALA),
                'credito' => bcadd((string) ($porForma['credito']->total ?? '0'), (string) $sv->total, self::ESCALA),
                'ventas_contado' => (int) ($porForma['contado']->ventas ?? 0),
                'ventas_credito' => (int) ($porForma['credito']->ventas ?? 0) + (int) $sv->ventas,
            ],
            'margen' => [
                'margen' => $g['margen'] ?? null,
                'margen_porcentaje' => $g['margen_porcentaje'] ?? null,
                'base_con_costo' => $g['base_con_costo'] ?? '0.00',
                'costo' => $g['costo'] ?? '0.00',
                'cobertura_costo' => $unidades > 0 ? round(($g['unidades_con_costo'] / $unidades) * 100, 1) : null,
                'unidades_sin_costo' => $g['unidades_sin_costo'] ?? 0,
            ],
        ];
    }

    /** Inventario disponible: unidades, valor a costo, valor a precio de venta sin IVA y unidades sin costo. */
    private function inventario(array $f): array
    {
        $disponibles = $this->referencias($f)->join('unidades as u', 'u.referencia_id', '=', 'r.id')->where('u.estado', 'disponible');
        $costo = $this->costoDeConsulta($disponibles->clone());
        $precios = $disponibles->clone()
            ->selectRaw('r.iva_venta_porcentaje as iva, SUM(r.precio_venta) as precio')
            ->groupBy('r.iva_venta_porcentaje')
            ->get();
        // S-45 c: precio_venta incluye IVA (S-33); la base sale de TotalesVenta.
        $venta = TotalesVenta::desglosar($precios->map(fn ($x) => ['precio' => $x->precio, 'iva' => $x->iva, 'vigente' => true]));

        return [
            'unidades' => $costo['con_costo'] + $costo['sin_costo'],
            'valor_costo' => $costo['costo'],
            'valor_venta_sin_iva' => $venta->base,
            'unidades_sin_costo' => $costo['sin_costo'],
        ];
    }

    /** Serie diaria del período con los días sin ventas en 0 (día local de Bogotá). */
    private function serieDiaria(array $f): array
    {
        $serie = [];
        for ($d = Carbon::parse($f['desde']); $d->lte(Carbon::parse($f['hasta'])); $d->addDay()) {
            $serie[$d->toDateString()] = ['dia' => $d->toDateString(), 'contado' => '0.00', 'credito' => '0.00', 'total' => '0.00', 'unidades' => 0, 'ventas' => 0];
        }

        // Una fila por venta (agregada en SQL); el día local se asigna con RangoFechas.
        $porVenta = $this->vendidos($f)
            ->selectRaw('v.id, v.cerrada_at, v.forma_pago, COUNT(*) as n, SUM(vi.precio_unitario) as total')
            ->groupBy('v.id', 'v.cerrada_at', 'v.forma_pago')
            ->get();
        foreach ($porVenta as $v) {
            $dia = RangoFechas::diaLocal($v->cerrada_at);
            if (! isset($serie[$dia])) {
                continue;
            }
            $forma = $v->forma_pago === 'credito' ? 'credito' : 'contado';
            $serie[$dia][$forma] = bcadd($serie[$dia][$forma], (string) $v->total, self::ESCALA);
            $serie[$dia]['total'] = bcadd($serie[$dia]['total'], (string) $v->total, self::ESCALA);
            $serie[$dia]['unidades'] += (int) $v->n;
            $serie[$dia]['ventas']++;
        }

        // S-73: ventas a crédito de solo valor, como crédito del día de su registro (sin unidades).
        foreach ($this->creditosSoloValor($f)->get(['v.cerrada_at', 'v.valor_total']) as $v) {
            $dia = RangoFechas::diaLocal($v->cerrada_at);
            if (! isset($serie[$dia])) {
                continue;
            }
            $serie[$dia]['credito'] = bcadd($serie[$dia]['credito'], (string) $v->valor_total, self::ESCALA);
            $serie[$dia]['total'] = bcadd($serie[$dia]['total'], (string) $v->valor_total, self::ESCALA);
            $serie[$dia]['ventas']++;
        }

        return $serie;
    }

    /**
     * S-73: ventas a crédito de solo valor cerradas en el período y sin anular (índice estado,
     * cerrada_at). No tienen referencias: con un filtro de categoría o proveedor no se pueden
     * atribuir y no entran.
     */
    private function creditosSoloValor(array $f): Builder
    {
        [$ini, $fin] = $this->limites($f);

        // Índice ventas(forma_pago, estado, cerrada_at): solo las de crédito del período. La anulación,
        // con LEFT JOIN por reversos.venta_id (un registro de solo valor tiene a lo sumo un reverso).
        return DB::table('ventas as v')
            ->leftJoin('reversos as rv', 'rv.venta_id', '=', 'v.id')
            ->where('v.forma_pago', 'credito')
            ->where('v.estado', EstadoVenta::Cerrada->value)
            ->where('v.cerrada_at', '>=', $ini)
            ->where('v.cerrada_at', '<', $fin)
            ->whereNotNull('v.valor_total')
            ->whereNull('rv.id')
            ->when($f['categoria_id'] || $f['proveedor_id'], fn ($q) => $q->whereRaw('1 = 0'));
    }

    /** Clase ABC por valor de venta: acumulado ANTES de la referencia < 80 % → A, < 95 % → B, si no C. */
    private function clasesAbc(array $grupos, string $totalValor): array
    {
        $orden = collect($grupos)->map(fn ($g) => $g['total'])->sort(fn ($a, $b) => bccomp($b, $a, self::ESCALA));
        $limA = (float) config('panel.abc.A');
        $limB = (float) config('panel.abc.B');
        $acumulado = '0';
        $clases = [];
        foreach ($orden as $id => $valor) {
            $previo = $this->porcentaje($acumulado, $totalValor) ?? 0.0;
            $clases[$id] = $previo < $limA ? 'A' : ($previo < $limB ? 'B' : 'C');
            $acumulado = bcadd($acumulado, $valor, self::ESCALA);
        }

        return $clases;
    }

    /** Costo de unidades de una consulta que ya une `unidades as u`. */
    private function costoDeConsulta(Builder $consulta): array
    {
        $lineas = $consulta->selectRaw('u.ingreso_item_id as linea, COUNT(*) as n')->groupBy('u.ingreso_item_id')->get();

        return CostoEfectivo::sumar(
            $lineas->map(fn ($x) => ['linea' => $x->linea === null ? null : (int) $x->linea, 'unidades' => (int) $x->n]),
            $this->costosDe($lineas->pluck('linea')),
        );
    }

    /** Costo de las unidades disponibles de las referencias de una subconsulta de ids. */
    private function costoDeDisponibles(Builder $ids): array
    {
        return $this->costoDeConsulta(DB::table('unidades as u')->where('u.estado', 'disponible')->whereIn('u.referencia_id', $ids));
    }

    /** @return array<int, array{costo: string, sin_costo: int}> */
    private function costoPorReferencia(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $lineas = DB::table('unidades as u')->where('u.estado', 'disponible')->whereIn('u.referencia_id', $ids)
            ->selectRaw('u.referencia_id, u.ingreso_item_id as linea, COUNT(*) as n')
            ->groupBy('u.referencia_id', 'u.ingreso_item_id')
            ->get();
        $costos = $this->costosDe($lineas->pluck('linea'));

        return $lineas->groupBy('referencia_id')->map(fn ($g) => CostoEfectivo::sumar(
            $g->map(fn ($x) => ['linea' => $x->linea === null ? null : (int) $x->linea, 'unidades' => (int) $x->n]),
            $costos,
        ))->all();
    }

    /** Universales (de los dados) con alguna venta neta cerrada desde `$desde`. */
    private function universalesQueRotan(array $universales, Carbon $desde): array
    {
        if ($universales === []) {
            return [];
        }

        return DB::table('venta_items as vi')
            ->join('ventas as v', 'v.id', '=', 'vi.venta_id')
            ->join('unidades as u', 'u.id', '=', 'vi.unidad_id')
            ->join('referencias as r', 'r.id', '=', 'u.referencia_id')
            ->leftJoin('reverso_items as ri', 'ri.venta_item_id', '=', 'vi.id')
            ->where('v.estado', EstadoVenta::Cerrada->value)
            ->where('v.cerrada_at', '>=', $desde)
            ->whereNull('ri.id')
            ->whereIn('r.codigo_universal_id', $universales)
            ->distinct()
            ->pluck('r.codigo_universal_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return array<int, string> */
    private function costosDe(Collection $lineas): array
    {
        $faltan = $lineas->filter()->map(fn ($id) => (int) $id)->unique()->reject(fn ($id) => array_key_exists($id, $this->costos));
        if ($faltan->isNotEmpty()) {
            $nuevos = CostoEfectivo::deLineas($faltan);
            foreach ($faltan as $id) {
                $this->costos[$id] = $nuevos[$id] ?? null;
            }
            foreach ($nuevos as $id => $c) {
                $this->costos[$id] = $c;
            }
        }

        return array_filter($this->costos, fn ($c) => $c !== null);
    }

    /** @return array<int, array<string, mixed>> */
    private function infoReferencias(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('referencias as r')
            ->join('proveedores as p', 'p.id', '=', 'r.proveedor_id')
            ->join('categorias as c', 'c.id', '=', 'r.categoria_id')
            ->leftJoin('codigos_universales as cu', 'cu.id', '=', 'r.codigo_universal_id')
            ->whereIn('r.id', $ids)
            ->get([
                'r.id', 'r.codigo_referencia', 'r.descripcion', 'r.calidad', 'r.codigo_universal_id', 'cu.codigo as universal',
                'p.id as proveedor_id', 'p.nombre as proveedor', 'c.id as categoria_id', 'c.nombre as categoria',
            ])
            ->mapWithKeys(fn ($r) => [$r->id => $this->filaReferencia($r)])
            ->all();
    }

    private function filaReferencia(object $r): array
    {
        return [
            'id' => (int) $r->id,
            'codigo_referencia' => $r->codigo_referencia,
            'descripcion' => $r->descripcion,
            'calidad' => $r->calidad,
            'proveedor' => ['id' => (int) $r->proveedor_id, 'nombre' => $r->proveedor],
            'categoria' => isset($r->categoria_id) ? ['id' => (int) $r->categoria_id, 'nombre' => $r->categoria] : null,
            'universal' => $r->codigo_universal_id ? ['id' => (int) $r->codigo_universal_id, 'codigo' => $r->universal] : null,
        ];
    }

    /** CASE de rangos de antigüedad (config panel.antiguedad) contra la fecha de la unidad. */
    private function casoAntiguedad(): array
    {
        $ahora = Carbon::now();
        $partes = [];
        $enlaces = [];
        $ultimo = null;
        foreach (config('panel.antiguedad') as $rango => $tope) {
            if ($tope === null) {
                $ultimo = $rango;

                continue;
            }
            $partes[] = 'WHEN u.created_at >= ? THEN ?';
            $enlaces[] = $ahora->copy()->subDays($tope);
            $enlaces[] = $rango;
        }

        return ['(CASE '.implode(' ', $partes).' ELSE ? END)', [...$enlaces, $ultimo]];
    }

    /** @return array{0: Carbon, 1: Carbon} límites del período en la zona de la app */
    private function limites(array $f): array
    {
        return [RangoFechas::inicio($f['desde']), RangoFechas::finExclusivo($f['hasta'])];
    }

    private function diasDe(array $f): int
    {
        return (int) Carbon::parse($f['desde'])->diffInDays(Carbon::parse($f['hasta'])) + 1;
    }

    /** Período inmediatamente anterior, de igual duración. */
    private function periodoAnterior(array $f): array
    {
        $dias = $this->diasDe($f);
        $hasta = Carbon::parse($f['desde'])->subDay();

        return [...$f, 'desde' => $hasta->copy()->subDays($dias - 1)->toDateString(), 'hasta' => $hasta->toDateString()];
    }

    private function rango(array $f): array
    {
        return ['desde' => $f['desde'], 'hasta' => $f['hasta'], 'dias_periodo' => $this->diasDe($f)];
    }

    private function variacion(string $actual, string $anterior): ?float
    {
        if (bccomp($anterior, '0', self::ESCALA) === 0) {
            return null;
        }

        return round((float) bcdiv(bcmul(bcsub($actual, $anterior, self::ESCALA), '100', 6), $anterior, 6), 1);
    }

    private function porcentaje(string $parte, string $total): ?float
    {
        return bccomp($total, '0', self::ESCALA) > 0 ? round((float) bcdiv(bcmul($parte, '100', 6), $total, 6), 1) : null;
    }

    private function sumarCampo(array $serie, string $campo): string
    {
        return array_reduce($serie, fn ($s, $d) => bcadd($s, $d[$campo], self::ESCALA), '0.00');
    }
}
