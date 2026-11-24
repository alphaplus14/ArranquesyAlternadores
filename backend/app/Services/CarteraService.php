<?php

namespace App\Services;

use App\Enums\EstadoVenta;
use App\Enums\FormaPago;
use App\Models\AbonoCliente;
use App\Models\Cliente;
use App\Support\RangoFechas;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
        $coincidencias = $this->ventasQueCoinciden($corte, $filtros['busqueda'] ?? null);

        // Una sola consulta: una fila AGREGADA por cliente (no las ventas). Los filtros, los totales,
        // el orden y la página se resuelven sobre esas filas, sin repetir el cálculo FIFO.
        $filas = DB::query()->fromSub($this->porCliente($corte, $filtros['busqueda'] ?? null, null, $coincidencias->keys()->all()), 'c')
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
            $filas->forPage($pagina, $porPagina)
                ->map(fn ($f) => $this->filaCliente($f) + ['coincidencia' => $coincidencias->get((int) $f->id)])
                ->values(),
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

    /**
     * RF-18: detalle del cliente. Ventas a crédito cerradas con su total neto, vencimiento, abono
     * aplicado (FIFO), pendiente, estado y rango; los abonos (también los anulados) y el resumen.
     *
     * Filtros de la lista de ventas (S-30): busqueda (n.º de venta o factura Contapyme), estado
     * (todas|pendientes|vencidas|pagadas) y desde/hasta sobre la fecha de cierre (días de Bogotá,
     * hasta inclusivo). El FIFO se calcula sobre TODAS las ventas del cliente y los filtros se
     * aplican después, en la consulta exterior: filtrar antes cambiaría el pendiente. El resumen
     * (saldo, vencido, antigüedad) nunca cambia con los filtros.
     *
     * @param  array{busqueda?: string|null, estado?: string|null, desde?: string|null, hasta?: string|null}  $filtros
     * @return array<string, mixed>
     */
    public function detalle(Cliente $cliente, ?string $corte = null, array $filtros = []): array
    {
        $corte = $corte ?: RangoFechas::hoy();
        $todas = DB::query()->fromSub($this->ventasFifo($corte, $cliente->id), 'f');
        $totalVentas = (clone $todas)->count();

        $consulta = clone $todas;
        $this->filtrarVentas($consulta, $filtros);

        $ventas = $consulta
            ->orderBy('f.cerrada_at')
            ->orderBy('f.id')
            ->get()
            ->map(function ($v) {
                $neto = (float) $v->neto;
                $pendiente = (float) $v->pendiente;
                $dias = (int) $v->dias;

                return [
                    'id' => (int) $v->id,
                    'factura_contapyme' => $v->factura_contapyme,
                    'cerrada_at' => $v->cerrada_at,
                    'vence_el' => $v->vence_el,
                    'total' => self::pesos($neto),
                    'aplicado' => self::pesos($neto - $pendiente),
                    'pendiente' => self::pesos($pendiente),
                    ...self::estadoVenta($pendiente, $dias),
                ];
            });

        $abonos = AbonoCliente::query()
            ->where('cliente_id', $cliente->id)
            ->with('usuario:id,name', 'anulacion.usuario:id,name')
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->get();

        return [
            'resumen' => $this->resumenDe($cliente, $corte),
            'ventas' => $ventas->values()->all(),
            'ventas_total' => $totalVentas,
            'abonos' => $abonos,
        ];
    }

    /** Filtros de la lista de ventas del detalle, sobre las filas ya calculadas con FIFO. */
    protected function filtrarVentas(Builder $q, array $filtros): void
    {
        $termino = self::terminoVenta($filtros['busqueda'] ?? null);
        if ($termino) {
            $q->where(function ($w) use ($termino) {
                if ($termino['id'] !== null) {
                    $w->orWhere('f.id', $termino['id']);
                }
                if ($termino['factura'] !== null) {
                    $w->orWhere('f.factura_contapyme', 'like', $termino['factura']);
                }
            });
        }

        match ($filtros['estado'] ?? 'todas') {
            'pendientes' => $q->where('f.pendiente', '>', 0),
            'vencidas' => $q->where('f.pendiente', '>', 0)->where('f.dias', '>', 0),
            'pagadas' => $q->where('f.pendiente', '<=', 0),
            default => null,
        };

        if ($filtros['desde'] ?? null) {
            $q->where('f.cerrada_at', '>=', RangoFechas::inicio($filtros['desde'])->format('Y-m-d H:i:s'));
        }
        if ($filtros['hasta'] ?? null) {
            $q->where('f.cerrada_at', '<', RangoFechas::finExclusivo($filtros['hasta'])->format('Y-m-d H:i:s'));
        }
    }

    /**
     * Cómo se busca una venta (S-30). "#4" = solo la venta 4. "4" = la venta 4 o una factura de
     * Contapyme que empiece por "4". Cualquier otro texto = prefijo de factura. Sin espacios en los
     * extremos; la intercalación de MySQL (utf8mb4_unicode_ci) ya ignora mayúsculas. null = sin término.
     *
     * @return array{id: int|null, factura: string|null}|null
     */
    protected static function terminoVenta(?string $busqueda): ?array
    {
        $t = trim((string) $busqueda);
        if ($t === '') {
            return null;
        }
        if (preg_match('/^#\s*(\d{1,18})$/', $t, $m)) {
            return ['id' => (int) $m[1], 'factura' => null];
        }

        return [
            'id' => preg_match('/^\d{1,18}$/', $t) ? (int) $t : null,
            'factura' => addcslashes($t, '%_\\').'%',
        ];
    }

    /**
     * GET /cartera: ventas a crédito cerradas al corte cuyo n.º o factura Contapyme coincide con la
     * búsqueda; la primera por cliente (cliente_id => {venta_id, factura_contapyme}). Dos consultas
     * con índice (PK y ventas.factura_contapyme) en vez de un OR que obligaría a recorrer la tabla.
     *
     * @return Collection<int, array{venta_id: int, factura_contapyme: string|null}>
     */
    protected function ventasQueCoinciden(string $corte, ?string $busqueda): Collection
    {
        $termino = self::terminoVenta($busqueda);
        if (! $termino) {
            return collect();
        }

        $fin = RangoFechas::finExclusivo($corte)->format('Y-m-d H:i:s');
        $base = fn () => DB::table('ventas')
            ->where('forma_pago', FormaPago::Credito->value)
            ->where('estado', EstadoVenta::Cerrada->value)
            ->whereNotNull('cliente_id')
            ->where('cerrada_at', '<', $fin)
            ->select('id', 'cliente_id', 'factura_contapyme');

        $filas = collect();
        if ($termino['id'] !== null) {
            $filas = $filas->concat($base()->where('id', $termino['id'])->get());
        }
        if ($termino['factura'] !== null) {
            $filas = $filas->concat($base()->where('factura_contapyme', 'like', $termino['factura'])->orderBy('factura_contapyme')->limit(500)->get());
        }

        return $filas
            ->groupBy(fn ($v) => (int) $v->cliente_id)
            ->map(fn ($vs) => ['venta_id' => (int) $vs->first()->id, 'factura_contapyme' => $vs->first()->factura_contapyme]);
    }

    /**
     * RF-18: estado de cuenta del cliente. Ventas a crédito (al cerrarse, por el valor de todos sus
     * ítems), cada reverso de esas ventas (uno hecho con la venta abierta se fecha al cierre, para
     * no quedar antes de la venta; no se adivina por la hora, que tiene resolución de segundos),
     * abonos y anulaciones de abono, en orden de fecha, con saldo acumulado. Con desde/hasta, arranca con el saldo
     * anterior a "desde". Las fechas son días de Bogotá; el abono usa su fecha.
     *
     * @return array<string, mixed>
     */
    public function estadoCuenta(Cliente $cliente, ?string $desde = null, ?string $hasta = null): array
    {
        $zona = config('app.zona_local', 'America/Bogota');
        $local = fn ($momento) => Carbon::parse($momento, config('app.timezone'))->setTimezone($zona);
        $movimientos = collect();

        // Ventas a crédito cerradas, por el valor de todos sus ítems; los reversos van aparte.
        $ventas = DB::table('ventas as v')
            ->join('venta_items as vi', 'vi.venta_id', '=', 'v.id')
            ->where('v.cliente_id', $cliente->id)
            ->where('v.forma_pago', FormaPago::Credito->value)
            ->where('v.estado', EstadoVenta::Cerrada->value)
            ->groupBy('v.id', 'v.cerrada_at', 'v.vence_el', 'v.factura_contapyme')
            ->selectRaw('v.id, v.cerrada_at, v.vence_el, v.factura_contapyme,
                SUM(COALESCE(vi.precio_unitario, 0)) AS valor')
            ->get();

        foreach ($ventas as $v) {
            $momento = $local($v->cerrada_at);
            $movimientos->push([
                'tipo' => 'venta', 'dia' => $momento->toDateString(), 'momento' => $momento->toIso8601String(), 'orden' => 1,
                'descripcion' => "Venta a crédito #{$v->id}".($v->factura_contapyme ? " · factura {$v->factura_contapyme}" : ''),
                'venta_id' => (int) $v->id, 'vence_el' => $v->vence_el,
                'cargo' => (float) $v->valor, 'abono' => 0.0,
            ]);
        }

        // Reversos de esas ventas; nunca antes del cierre de la venta.
        $reversos = DB::table('reversos as r')
            ->join('ventas as v', 'v.id', '=', 'r.venta_id')
            ->join('reverso_items as ri', 'ri.reverso_id', '=', 'r.id')
            ->join('venta_items as vi', 'vi.id', '=', 'ri.venta_item_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.usuario_id')
            ->where('v.cliente_id', $cliente->id)
            ->where('v.forma_pago', FormaPago::Credito->value)
            ->where('v.estado', EstadoVenta::Cerrada->value)
            ->groupBy('r.id', 'r.venta_id', 'r.tipo', 'r.motivo', 'r.created_at', 'v.cerrada_at', 'u.name')
            ->selectRaw('r.id, r.venta_id, r.tipo, r.motivo, GREATEST(r.created_at, v.cerrada_at) AS momento, u.name AS usuario, SUM(COALESCE(vi.precio_unitario, 0)) AS valor')
            ->get();

        foreach ($reversos as $r) {
            $momento = $local($r->momento);
            $movimientos->push([
                'tipo' => 'reverso', 'dia' => $momento->toDateString(), 'momento' => $momento->toIso8601String(), 'orden' => 2,
                'descripcion' => ($r->tipo === 'devolucion' ? 'Devolución' : 'Anulación')." de la venta #{$r->venta_id}",
                'venta_id' => (int) $r->venta_id, 'reverso_id' => (int) $r->id, 'motivo' => $r->motivo, 'usuario' => $r->usuario,
                'cargo' => 0.0, 'abono' => (float) $r->valor,
            ]);
        }

        // Abonos (también los anulados) y, aparte, sus anulaciones.
        $abonos = AbonoCliente::query()->where('cliente_id', $cliente->id)
            ->with('usuario:id,name', 'anulacion.usuario:id,name')
            ->get();

        foreach ($abonos as $a) {
            $movimientos->push([
                'tipo' => 'abono', 'dia' => $a->fecha->toDateString(), 'momento' => $local($a->created_at)->toIso8601String(), 'orden' => 3,
                'descripcion' => 'Abono · '.$a->medio->value.($a->referencia ? " · {$a->referencia}" : ''),
                'abono_id' => $a->id, 'medio' => $a->medio->value, 'referencia' => $a->referencia, 'nota' => $a->nota,
                'usuario' => $a->usuario?->name,
                'anulado' => $a->anulacion !== null,
                'anulacion' => $a->anulacion ? [
                    'motivo' => $a->anulacion->motivo,
                    'usuario' => $a->anulacion->usuario?->name,
                    'fecha' => $local($a->anulacion->created_at)->toIso8601String(),
                ] : null,
                'cargo' => 0.0, 'abono' => (float) $a->valor,
            ]);

            if ($a->anulacion) {
                $momento = $local($a->anulacion->created_at);
                $movimientos->push([
                    'tipo' => 'anulacion_abono', 'dia' => $momento->toDateString(), 'momento' => $momento->toIso8601String(), 'orden' => 4,
                    'descripcion' => "Anulación del abono del {$a->fecha->toDateString()}",
                    'abono_id' => $a->id, 'motivo' => $a->anulacion->motivo, 'usuario' => $a->anulacion->usuario?->name,
                    'cargo' => (float) $a->valor, 'abono' => 0.0,
                ]);
            }
        }

        $ordenados = $movimientos
            ->sort(fn ($x, $y) => [$x['dia'], $x['momento'], $x['orden']] <=> [$y['dia'], $y['momento'], $y['orden']])
            ->values();

        $saldoInicial = 0.0;
        $saldo = 0.0;
        $visibles = [];
        foreach ($ordenados as $m) {
            $saldo += $m['cargo'] - $m['abono'];
            if ($desde && $m['dia'] < $desde) {
                $saldoInicial = $saldo;

                continue;
            }
            if ($hasta && $m['dia'] > $hasta) {
                continue;
            }
            $visibles[] = [
                ...collect($m)->except('orden')->all(),
                'cargo' => self::pesos($m['cargo']),
                'abono' => self::pesos($m['abono']),
                'saldo' => self::pesos($saldo),
            ];
        }
        $saldoFinal = $visibles ? end($visibles)['saldo'] : self::pesos($saldoInicial);

        return [
            'cliente' => ['id' => $cliente->id, 'nombre' => $cliente->nombre, 'documento' => $cliente->documento],
            'desde' => $desde,
            'hasta' => $hasta,
            'saldo_inicial' => self::pesos($saldoInicial),
            'movimientos' => $visibles,
            'saldo_final' => $saldoFinal,
        ];
    }

    /**
     * Estado de una venta para la pantalla: sin pendiente "al día"; por vencer "vence en N días"
     * (o "vence hoy"); vencida "vencida hace N días". El rango es el de S-13.
     *
     * @return array{estado: string, estado_texto: string, dias_vencida: int, rango: string|null}
     */
    protected static function estadoVenta(float $pendiente, int $dias): array
    {
        if ($pendiente <= 0) {
            return ['estado' => 'al_dia', 'estado_texto' => 'al día', 'dias_vencida' => 0, 'rango' => null];
        }

        $rango = match (true) {
            $dias <= 0 => 'al_dia',
            $dias <= 30 => 'd1_30',
            $dias <= 60 => 'd31_60',
            $dias <= 90 => 'd61_90',
            default => 'mas_90',
        };

        if ($dias > 0) {
            return ['estado' => 'vencida', 'estado_texto' => $dias === 1 ? 'vencida hace 1 día' : "vencida hace {$dias} días", 'dias_vencida' => $dias, 'rango' => $rango];
        }

        $faltan = -$dias;

        return ['estado' => 'por_vencer', 'estado_texto' => match ($faltan) {
            0 => 'vence hoy', 1 => 'vence en 1 día', default => "vence en {$faltan} días"
        }, 'dias_vencida' => 0, 'rango' => $rango];
    }

    /** Saldo de un cliente a hoy (S-30: la única fuente). */
    public function saldoDe(Cliente $cliente): string
    {
        return $this->resumenDe($cliente)['saldo'];
    }

    /**
     * Una fila por cliente: ventas, abonado, saldo, vencido, próximo vencimiento y rangos.
     * Con búsqueda, filtra por prefijo de nombre o documento (índices; sin comodín inicial) o por
     * los clientes dueños de una venta que coincide por n.º o factura ($idsPorVenta).
     */
    protected function porCliente(string $corte, ?string $busqueda = null, ?int $clienteId = null, array $idsPorVenta = []): Builder
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
                ->orWhere('cl.documento', 'like', $prefijo)
                ->when($idsPorVenta, fn ($o) => $o->orWhereIn('cl.id', $idsPorVenta))))
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
            ->selectRaw('x.id, x.cliente_id, x.cerrada_at, x.vence_el, x.factura_contapyme, x.neto, x.bruto,
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
            ->groupBy('v.id', 'v.cliente_id', 'v.cerrada_at', 'v.vence_el', 'v.factura_contapyme')
            ->selectRaw('v.id, v.cliente_id, v.cerrada_at, v.vence_el, v.factura_contapyme,
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
