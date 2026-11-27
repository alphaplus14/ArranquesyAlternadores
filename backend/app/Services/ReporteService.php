<?php

namespace App\Services;

use App\Enums\EstadoIngreso;
use App\Enums\EstadoVenta;
use App\Enums\FormaPago;
use App\Enums\TipoDocumentoVenta;
use App\Enums\TipoIngreso;
use App\Models\Cliente;
use App\Models\IngresoItem;
use App\Models\Proveedor;
use App\Models\Referencia;
use App\Support\Antiguedad;
use App\Support\TotalesIngreso;
use App\Support\TotalesVenta;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * RF-18: informes en pantalla (no se exportan). Cada informe devuelve sus filas y los totales
 * de todas ellas, no solo de la página; el controlador pagina. Los totales son la suma de las
 * filas (criterio de aceptación de RF-18). Solo lectura.
 */
class ReporteService
{
    private const ESCALA = 2;

    public function __construct(private readonly CarteraService $cartera) {}

    /**
     * RF-08 / S-47: referencias ACTIVAS (S-26) con existencia 0, de todos los proveedores salvo que
     * se filtre por proveedor o categoría. Orden: nombre del proveedor y código (el JOIN a proveedores
     * es solo para ordenar). Índices: (activo, existencia) sin proveedor; (proveedor_id, existencia)
     * con él. El orden por nombre se resuelve con un filesort sobre las agotadas, no sobre la tabla.
     * `universal_existencia` (regla 2): existencia de las demás referencias activas de cada universal
     * de la página, en UNA consulta agregada (la agotada aporta 0 a la suma).
     *
     * @param  array{proveedor_id?: int|null, categoria_id?: int|null}  $filtros
     */
    public function agotados(array $filtros, int $porPagina): LengthAwarePaginator
    {
        $pagina = Referencia::query()
            ->select('referencias.*')
            ->join('proveedores as p', 'p.id', '=', 'referencias.proveedor_id')
            ->where('referencias.existencia', 0)
            ->activas()
            ->when($filtros['proveedor_id'] ?? null, fn ($q, $v) => $q->where('referencias.proveedor_id', $v))
            ->when($filtros['categoria_id'] ?? null, fn ($q, $v) => $q->where('referencias.categoria_id', $v))
            ->with(['proveedor:id,nombre', 'categoria:id,nombre,admite_codigo_universal', 'codigoUniversal:id,codigo'])
            ->orderBy('p.nombre')
            ->orderBy('referencias.codigo_referencia')
            ->orderBy('referencias.id')
            ->paginate($porPagina);

        $universales = $pagina->getCollection()->pluck('codigo_universal_id')->filter()->unique()->values();
        $sumas = $universales->isEmpty() ? collect() : Referencia::query()
            ->activas()
            ->whereIn('codigo_universal_id', $universales)
            ->groupBy('codigo_universal_id')
            ->selectRaw('codigo_universal_id, SUM(existencia) as total')
            ->pluck('total', 'codigo_universal_id');

        $pagina->getCollection()->each(fn (Referencia $r) => $r->setAttribute(
            'universal_existencia',
            $r->codigo_universal_id === null ? null : (int) ($sumas[$r->codigo_universal_id] ?? 0),
        ));

        return $pagina;
    }

    /**
     * 1. Compras por período (fecha del documento) y proveedor, de contado y a crédito. Una
     * remisión cubierta por una factura confirmada no cuenta: cuenta la factura (RF-19).
     *
     * @return array{filas: Collection<int, array<string, mixed>>, totales: array<string, string>}
     */
    public function compras(CarbonInterface $desde, CarbonInterface $hasta, ?int $proveedorId, array $filtros = []): array
    {
        // S-88: forma de pago, tipo de documento y número (en la consulta, por índices de ingresos).
        $q = self::normalizar($filtros['q'] ?? '');
        $ingresos = $this->cartera->ingresosQueCuentan($proveedorId ? [$proveedorId] : null, Carbon::now())
            ->join('proveedores', 'proveedores.id', '=', 'ingresos.proveedor_id')
            ->whereBetween('ingresos.fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->when($filtros['forma_pago'] ?? null, fn ($w, $v) => $w->where('ingresos.forma_pago', $v))
            ->when($filtros['tipo'] ?? null, fn ($w, $v) => $w->where('ingresos.tipo', $v))
            ->when($q !== '', fn ($w) => $w->where('ingresos.numero_documento', 'like', '%'.addcslashes($q, '%_\\').'%'))
            ->orderBy('ingresos.fecha')->orderBy('ingresos.id')
            ->get([
                'ingresos.id', 'ingresos.tipo', 'ingresos.numero_documento', 'ingresos.fecha', 'ingresos.forma_pago',
                'ingresos.descuento_documento', 'ingresos.total', 'ingresos.proveedor_id', 'proveedores.nombre as proveedor',
            ]);

        $items = IngresoItem::query()->whereIn('ingreso_id', $ingresos->pluck('id'))->get()->groupBy('ingreso_id');

        $filas = $ingresos->map(function ($i) use ($items) {
            $t = TotalesIngreso::calcular($items->get($i->id, collect()), $i->descuento_documento);

            return [
                'id' => (int) $i->id,
                'tipo' => $i->tipo,
                'numero_documento' => $i->numero_documento,
                'proveedor' => ['id' => (int) $i->proveedor_id, 'nombre' => $i->proveedor],
                'fecha' => $i->fecha,
                'forma_pago' => $i->forma_pago,
                'subtotal' => $t->subtotalBruto,
                'descuento' => bcadd($t->descuentoLineas, $t->descuentoDocumento, self::ESCALA),
                'base_gravada' => $t->baseGravada,
                'base_sin_iva' => $t->baseSinIva,
                'iva' => $t->iva,
                'total' => (string) $i->total,
            ];
        });

        return ['filas' => $filas, 'totales' => $this->sumar($filas, ['subtotal', 'descuento', 'base_gravada', 'base_sin_iva', 'iva', 'total'])];
    }

    /**
     * 2. Ventas a crédito cerradas en el período, por cliente, netas de reversos. Una venta
     * reversada por completo no aparece. Base e IVA salen del precio con IVA incluido (S-33, TotalesVenta).
     * S-73: las ventas de solo valor (sin ítems) entran con total = valor_total, su tipo y número, y
     * base e IVA en null; los totales de base e IVA suman solo las ventas con ítems (sumar ignora null).
     *
     * @return array{filas: Collection<int, array<string, mixed>>, totales: array<string, string>}
     */
    public function ventasCredito(CarbonInterface $desde, CarbonInterface $hasta, ?int $clienteId, array $filtros = []): array
    {
        $rango = [Carbon::instance($desde)->startOfDay(), Carbon::instance($hasta)->endOfDay()];
        // S-88: vigentes (por defecto, como antes), anuladas o todas. Una venta con ítems está anulada
        // si todos sus ítems tienen reverso; una de solo valor, si tiene reverso (S-73).
        $estado = $filtros['estado'] ?? 'vigentes';

        // Ítems de las ventas del período; en "vigentes" solo los no reversados (como antes).
        $items = DB::table('venta_items')
            ->join('ventas', 'ventas.id', '=', 'venta_items.venta_id')
            ->leftJoin('reverso_items', 'reverso_items.venta_item_id', '=', 'venta_items.id')
            ->where('ventas.forma_pago', FormaPago::Credito->value)
            ->where('ventas.estado', EstadoVenta::Cerrada->value)
            ->whereBetween('ventas.cerrada_at', $rango)
            ->when($clienteId, fn ($q) => $q->where('ventas.cliente_id', $clienteId))
            ->whereNotNull('venta_items.precio_unitario')
            ->get(['venta_items.venta_id', 'venta_items.precio_unitario', 'venta_items.iva_porcentaje', 'reverso_items.id as reverso'])
            ->groupBy('venta_id');
        $vigentesConItems = $items->map(fn (Collection $v) => $v->whereNull('reverso')->values())->filter(fn (Collection $v) => $v->isNotEmpty());
        $anuladasConItems = $items->filter(fn (Collection $v) => $v->whereNull('reverso')->isEmpty());

        // S-73: ventas a crédito de solo valor del período, con o sin reverso.
        $soloValor = DB::table('ventas')
            ->where('ventas.forma_pago', FormaPago::Credito->value)
            ->where('ventas.estado', EstadoVenta::Cerrada->value)
            ->whereNotNull('ventas.valor_total')
            ->whereBetween('ventas.cerrada_at', $rango)
            ->when($clienteId, fn ($q) => $q->where('ventas.cliente_id', $clienteId))
            ->selectRaw('ventas.id, EXISTS (SELECT 1 FROM reversos WHERE reversos.venta_id = ventas.id) as anulada')
            ->get();

        $ids = collect();
        if ($estado !== 'anuladas') {
            $ids = $ids->merge($vigentesConItems->keys())->merge($soloValor->where('anulada', 0)->pluck('id'));
        }
        if ($estado !== 'vigentes') {
            $ids = $ids->merge($anuladasConItems->keys())->merge($soloValor->where('anulada', 1)->pluck('id'));
        }

        $ventas = DB::table('ventas')
            ->join('clientes', 'clientes.id', '=', 'ventas.cliente_id')
            ->whereIn('ventas.id', $ids->unique()->values())
            ->orderBy('ventas.cerrada_at')->orderBy('ventas.id')
            ->get(['ventas.id', 'ventas.cerrada_at', 'ventas.vence_el', 'ventas.factura_contapyme', 'ventas.tipo_documento', 'ventas.valor_total', 'ventas.cliente_id', 'clientes.nombre as cliente']);

        $filas = $ventas->map(function ($v) use ($vigentesConItems, $anuladasConItems, $soloValor) {
            $base = [
                'id' => (int) $v->id,
                'cliente' => ['id' => (int) $v->cliente_id, 'nombre' => $v->cliente],
                'fecha' => Carbon::parse($v->cerrada_at)->toDateString(),
                'vence_el' => $v->vence_el,
                'factura_contapyme' => $v->factura_contapyme,
            ];
            if ($v->valor_total !== null && $v->tipo_documento !== null) {
                return [
                    ...$base,
                    'tipo_documento' => $v->tipo_documento,
                    'documento' => TipoDocumentoVenta::from($v->tipo_documento)->descripcion($v->factura_contapyme),
                    'items' => 0,
                    'base' => null,
                    'iva' => null,
                    'total' => (string) $v->valor_total,
                    'anulada' => (bool) ($soloValor->firstWhere('id', $v->id)->anulada ?? false),
                ];
            }

            $anulada = ! $vigentesConItems->has($v->id);
            $lineas = $anulada ? $anuladasConItems[$v->id] : $vigentesConItems[$v->id];
            $valor = TotalesVenta::desglosar($lineas->map(fn ($i) => [
                'precio' => $i->precio_unitario, 'iva' => $i->iva_porcentaje, 'vigente' => true,
            ]));

            return [
                ...$base,
                'tipo_documento' => null,
                'documento' => null,
                'items' => $lineas->count(),
                'base' => $valor->base,
                'iva' => $valor->ivaIncluido,
                'total' => $valor->total,
                // Anulada: su valor original (todos sus ítems quedaron reversados).
                'anulada' => $anulada,
            ];
        });

        // S-88: tipo de documento. Factura = de solo valor "factura" o con ítems y número de factura de
        // Contapyme; remisión = de solo valor "remisión" (las ventas con ítems no llevan ese tipo).
        $tipo = $filtros['tipo'] ?? null;
        if ($tipo !== null) {
            $filas = $filas->filter(fn ($f) => $tipo === 'factura'
                ? ($f['tipo_documento'] === 'factura' || ($f['tipo_documento'] === null && $f['factura_contapyme'] !== null))
                : $f['tipo_documento'] === 'remision')->values();
        }
        $q = self::normalizar($filtros['q'] ?? '');
        if ($q !== '') {
            $filas = $filas->filter(fn ($f) => str_contains(self::normalizar((string) $f['factura_contapyme']), $q)
                || ltrim($q, '#') === (string) $f['id'])->values();
        }

        return ['filas' => $filas, 'totales' => $this->sumar($filas, ['base', 'iva', 'total'])];
    }

    /**
     * 3. Cartera de clientes a la fecha de corte: saldo por cliente con antigüedad.
     *
     * @return array{filas: Collection<int, array<string, mixed>>, totales: array<string, mixed>}
     */
    public function carteraClientes(CarbonInterface $corte, array $filtros = []): array
    {
        // Mismo cálculo que la pantalla de Cartera (CarteraService, S-30 / S-32).
        $filas = $this->cartera->resumenClientes($corte->toDateString())
            ->filter(fn ($f) => bccomp($f['saldo'], '0', self::ESCALA) !== 0)
            ->sortBy(fn ($f) => mb_strtolower($f['cliente']['nombre']))
            ->values();
        $filas = $this->filtrarTitulares($filas, 'cliente', $filtros);

        return ['filas' => $filas, 'totales' => $this->totalesTitulares($filas)];
    }

    /**
     * S-88: filtros de Cuentas por pagar y Cartera de clientes sobre la colección ya agrupada por
     * titular (sin recalcular por fila): texto (nombre sin tildes ni mayúsculas; NIT o documento sin
     * puntos, guiones ni espacios), estado del vencido, antigüedad mínima, saldo a favor y orden.
     *
     * @param  Collection<int, array<string, mixed>>  $filas
     * @param  array{q?: ?string, estado?: ?string, antiguedad?: ?string, a_favor?: bool, orden?: ?string}  $filtros
     * @return Collection<int, array<string, mixed>>
     */
    private function filtrarTitulares(Collection $filas, string $clave, array $filtros): Collection
    {
        $q = self::normalizar($filtros['q'] ?? '');
        $digitos = preg_replace('/\D+/', '', $q);
        $campoDoc = $clave === 'proveedor' ? 'nit' : 'documento';
        $rangosDesde = match ($filtros['antiguedad'] ?? null) {
            'mas_de_30' => ['d31_60', 'd61_90', 'mas_90'],
            'mas_de_60' => ['d61_90', 'mas_90'],
            'mas_de_90' => ['mas_90'],
            default => null,
        };
        $positivo = fn ($v) => bccomp((string) $v, '0', self::ESCALA) > 0;

        $filas = $filas->filter(function (array $f) use ($q, $digitos, $clave, $campoDoc, $rangosDesde, $filtros, $positivo) {
            if ($q !== '') {
                $nombre = self::normalizar($f[$clave]['nombre'] ?? '');
                $doc = preg_replace('/\D+/', '', (string) ($f[$clave][$campoDoc] ?? ''));
                if (! self::contieneTodas($nombre, $q) && ($digitos === '' || ! str_contains($doc, $digitos))) {
                    return false;
                }
            }
            $estado = $filtros['estado'] ?? null;
            if ($estado === 'con_vencido' && ! $positivo($f['vencido'])) {
                return false;
            }
            if ($estado === 'al_dia' && $positivo($f['vencido'])) {
                return false;
            }
            if ($rangosDesde !== null && ! collect($rangosDesde)->contains(fn ($r) => $positivo($f['rangos'][$r] ?? 0))) {
                return false;
            }

            return ! (($filtros['a_favor'] ?? false) && ! $positivo($f['a_favor']));
        });

        return match ($filtros['orden'] ?? null) {
            'saldo_desc' => $filas->sortByDesc(fn ($f) => (float) $f['saldo'])->values(),
            'vencido_desc' => $filas->sortByDesc(fn ($f) => (float) $f['vencido'])->values(),
            default => $filas->values(),
        };
    }

    /** Totales de lo filtrado (todas las filas, no la página) y titulares con saldo. */
    private function totalesTitulares(Collection $filas): array
    {
        $totales = $this->sumar($filas, ['cargos', 'abonos', 'saldo', 'a_favor', 'vencido']);
        $totales['rangos'] = collect(array_keys(config('cartera.rangos')))->mapWithKeys(fn ($r) => [
            $r => $filas->reduce(fn ($s, $f) => bcadd($s, $f['rangos'][$r], self::ESCALA), '0.00'),
        ])->all();
        $totales['titulares'] = $filas->count();
        $totales['con_saldo'] = $filas->filter(fn ($f) => bccomp($f['saldo'], '0', self::ESCALA) > 0)->count();

        return $totales;
    }

    /** S-88: todas las palabras de la búsqueda aparecen en el texto (en cualquier orden). */
    public static function contieneTodas(string $texto, string $busqueda): bool
    {
        foreach (preg_split('/\s+/', $busqueda) ?: [] as $palabra) {
            if ($palabra !== '' && ! str_contains($texto, $palabra)) {
                return false;
            }
        }

        return true;
    }

    /** S-88: minúsculas y sin tildes, para buscar sin distinguir. */
    public static function normalizar(?string $texto): string
    {
        return mb_strtolower(trim(Str::ascii((string) $texto)));
    }

    /**
     * 4. Cuentas por pagar a la fecha de corte: saldo por proveedor con antigüedad.
     *
     * @return array{filas: Collection<int, array<string, mixed>>, totales: array<string, mixed>}
     */
    public function cuentasPorPagar(CarbonInterface $corte, array $filtros = []): array
    {
        $documentos = $this->cartera->comprasCredito(null, $corte);
        $pagado = $this->cartera->pagado(null, $corte);
        $nombres = Proveedor::query()
            ->whereKey($documentos->keys()->merge(array_keys($pagado))->unique())
            ->get(['id', 'nombre', 'nit', 'plazo_dias'])->keyBy('id');

        $informe = $this->porTitular($nombres, $documentos, $pagado, $corte, 'proveedor');
        $filas = $this->filtrarTitulares($informe['filas'], 'proveedor', $filtros);

        return ['filas' => $filas, 'totales' => $this->totalesTitulares($filas)];
    }

    /**
     * 5. Estado de cuenta de un proveedor: ingresos a crédito confirmados, remisiones que una
     * factura confirmada cubrió (salen de la deuda ese día, RF-19), pagos y anulaciones de pagos.
     *
     * @return array{saldo_anterior: string, filas: Collection<int, array<string, mixed>>, totales: array<string, string>}
     */
    public function estadoCuentaProveedor(Proveedor $proveedor, CarbonInterface $desde, CarbonInterface $hasta, array $filtros = []): array
    {
        $fin = Carbon::instance($hasta)->endOfDay();
        $credito = fn () => DB::table('ingresos')
            ->where('ingresos.proveedor_id', $proveedor->id)
            ->where('ingresos.forma_pago', FormaPago::Credito->value)
            ->where('ingresos.estado', EstadoIngreso::Confirmado->value)
            ->whereNotNull('ingresos.total');

        $ingresos = $credito()
            ->where('ingresos.confirmado_at', '<=', $fin)
            ->get(['ingresos.id', 'ingresos.tipo', 'ingresos.numero_documento', 'ingresos.fecha', 'ingresos.vence_el', 'ingresos.confirmado_at', 'ingresos.total'])
            ->map(fn ($i) => $this->movimiento($i->confirmado_at, $i->tipo, $this->nombreDocumento($i->tipo, $i->numero_documento), $i->total, '0', [
                'ingreso_id' => (int) $i->id, 'fecha_documento' => $i->fecha, 'vence_el' => $i->vence_el,
            ]));

        $cubiertas = $credito()
            ->join('ingresos as facturas', 'facturas.id', '=', 'ingresos.factura_id')
            ->where('facturas.estado', EstadoIngreso::Confirmado->value)
            ->where('facturas.confirmado_at', '<=', $fin)
            ->get(['ingresos.id', 'ingresos.numero_documento', 'ingresos.total', 'facturas.confirmado_at', 'facturas.numero_documento as factura'])
            ->map(fn ($r) => $this->movimiento($r->confirmado_at, 'remision_cubierta', "Remisión {$r->numero_documento} cubierta por la factura {$r->factura}", '0', $r->total, [
                'ingreso_id' => (int) $r->id,
            ]));

        $movimientos = $ingresos->concat($cubiertas)->concat(
            $this->movimientosDinero('pagos_proveedores', 'pago_anulaciones', 'pago_id', 'proveedor_id', $proveedor->id, $fin, 'pago')
        );

        return $this->filtrarEstadoCuenta($this->estadoCuenta($movimientos, $desde), $filtros);
    }

    /**
     * S-88: estado de cuenta de un cliente para el contable, con el formato del de proveedores. El
     * cálculo es el de la cartera (CarteraService::estadoCuenta, S-30 a S-32 y S-73/S-74): aquí solo se
     * adapta la forma (detalle, cargo, abono, saldo corrido, saldo anterior y totales del período).
     */
    public function estadoCuentaCliente(Cliente $cliente, CarbonInterface $desde, CarbonInterface $hasta, array $filtros = []): array
    {
        $ec = $this->cartera->estadoCuenta($cliente, $desde->toDateString(), $hasta->toDateString());
        $filas = collect($ec['movimientos'])->map(fn (array $m) => [
            'fecha' => $m['dia'],
            'tipo' => $m['tipo'],
            'detalle' => $m['descripcion'].(! empty($m['aplicado_a']) && $m['tipo'] === 'abono' ? " → {$m['aplicado_a']}" : ''),
            'cargo' => $m['cargo'],
            'abono' => $m['abono'],
            'saldo' => $m['saldo'],
            'venta_id' => $m['venta_id'] ?? null,
            'abono_id' => $m['abono_id'] ?? null,
            'vence_el' => $m['vence_el'] ?? null,
        ]);

        return $this->filtrarEstadoCuenta([
            'saldo_anterior' => $ec['saldo_inicial'],
            'filas' => $filas,
            'totales' => [...$this->sumar($filas, ['cargo', 'abono']), 'saldo_final' => $ec['saldo_final']],
        ], $filtros);
    }

    /**
     * S-88: el filtro de tipo (cargos o abonos/pagos) y la búsqueda solo OCULTAN filas: el saldo
     * anterior, el saldo corrido de cada fila y los totales del período se calculan con todos los
     * movimientos. `movimientos_periodo` es M en "Mostrando N de M".
     */
    private function filtrarEstadoCuenta(array $informe, array $filtros): array
    {
        $periodo = $informe['filas']->count();
        $tipo = $filtros['tipo'] ?? 'todos';
        $q = self::normalizar($filtros['q'] ?? '');
        $positivo = fn ($v) => bccomp((string) $v, '0', self::ESCALA) > 0;

        $informe['filas'] = $informe['filas']->filter(fn (array $m) => ($tipo !== 'cargos' || $positivo($m['cargo']))
            && ($tipo !== 'abonos' || $positivo($m['abono']))
            && ($q === '' || str_contains(self::normalizar($m['detalle']), $q)))->values();
        $informe['movimientos_periodo'] = $periodo;
        $informe['filtrado'] = $tipo !== 'todos' || $q !== '';

        return $informe;
    }

    /**
     * 6a. Remisiones confirmadas que todavía no cubre una factura confirmada, con su valor
     * provisional (los costos de la remisión) y su antigüedad en días.
     *
     * @return array{filas: Collection<int, array<string, mixed>>, totales: array<string, string>}
     */
    public function remisionesPendientes(?int $proveedorId, ?int $masDeDias = null): array
    {
        $hoy = Carbon::today();
        $filas = DB::table('ingresos')
            ->join('proveedores', 'proveedores.id', '=', 'ingresos.proveedor_id')
            ->leftJoin('ingresos as facturas', 'facturas.id', '=', 'ingresos.factura_id')
            ->where('ingresos.tipo', TipoIngreso::Remision->value)
            ->where('ingresos.estado', EstadoIngreso::Confirmado->value)
            ->where(fn ($q) => $q->whereNull('ingresos.factura_id')->orWhere('facturas.estado', EstadoIngreso::Borrador->value))
            ->when($proveedorId, fn ($q) => $q->where('ingresos.proveedor_id', $proveedorId))
            ->orderBy('ingresos.fecha')->orderBy('ingresos.id')
            ->get([
                'ingresos.id', 'ingresos.numero_documento', 'ingresos.fecha', 'ingresos.forma_pago', 'ingresos.total',
                'ingresos.proveedor_id', 'proveedores.nombre as proveedor', 'facturas.id as factura_id', 'facturas.numero_documento as factura',
            ])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'numero_documento' => $r->numero_documento,
                'proveedor' => ['id' => (int) $r->proveedor_id, 'nombre' => $r->proveedor],
                'fecha' => $r->fecha,
                'forma_pago' => $r->forma_pago,
                'total' => $r->total === null ? null : (string) $r->total,
                'dias' => (int) Carbon::parse($r->fecha)->diffInDays($hoy, false),
                'factura_borrador' => $r->factura_id ? ['id' => (int) $r->factura_id, 'numero_documento' => $r->factura] : null,
            ]);
        // S-88: antigüedad (más de 30, 60 o 90 días desde la fecha de la remisión).
        if ($masDeDias !== null) {
            $filas = $filas->filter(fn ($f) => $f['dias'] > $masDeDias)->values();
        }

        return ['filas' => $filas, 'totales' => $this->sumar($filas, ['total'])];
    }

    /**
     * 6b. Facturas confirmadas que cubrieron menos de lo recibido por sus remisiones, con el
     * motivo, quién aceptó la diferencia y el detalle por referencia (RF-19).
     *
     * @return array{filas: Collection<int, array<string, mixed>>, totales: array<string, int>}
     */
    public function diferenciasRemision(?int $proveedorId): array
    {
        $facturas = DB::table('ingresos')
            ->join('proveedores', 'proveedores.id', '=', 'ingresos.proveedor_id')
            ->leftJoin('users', 'users.id', '=', 'ingresos.confirmado_por')
            ->where('ingresos.tipo', TipoIngreso::Factura->value)
            ->where('ingresos.estado', EstadoIngreso::Confirmado->value)
            ->where('ingresos.diferencia_aceptada', true)
            ->when($proveedorId, fn ($q) => $q->where('ingresos.proveedor_id', $proveedorId))
            ->orderByDesc('ingresos.confirmado_at')
            ->get([
                'ingresos.id', 'ingresos.numero_documento', 'ingresos.confirmado_at', 'ingresos.diferencia_motivo',
                'ingresos.proveedor_id', 'proveedores.nombre as proveedor', 'users.id as usuario_id', 'users.name as usuario',
            ]);

        $remisiones = DB::table('ingresos')->whereIn('factura_id', $facturas->pluck('id'))->orderBy('fecha')
            ->get(['id', 'factura_id', 'numero_documento'])->groupBy('factura_id');

        $recibido = DB::table('ingreso_items')
            ->join('ingresos', 'ingresos.id', '=', 'ingreso_items.ingreso_id')
            ->whereIn('ingresos.factura_id', $facturas->pluck('id'))
            ->groupBy('ingresos.factura_id', 'ingreso_items.referencia_id')
            ->get(['ingresos.factura_id', 'ingreso_items.referencia_id', DB::raw('SUM(ingreso_items.cantidad) as cantidad')])
            ->groupBy('factura_id');

        $facturado = DB::table('ingreso_items')
            ->whereIn('ingreso_id', $facturas->pluck('id'))
            ->groupBy('ingreso_id', 'referencia_id')
            ->get(['ingreso_id', 'referencia_id', DB::raw('SUM(cantidad) as cantidad')])
            ->groupBy('ingreso_id')
            ->map(fn (Collection $f) => $f->pluck('cantidad', 'referencia_id'));

        $codigos = DB::table('referencias')
            ->whereIn('id', $recibido->flatten(1)->pluck('referencia_id')->unique())
            ->pluck('codigo_referencia', 'id');

        $filas = $facturas->map(fn ($f) => [
            'id' => (int) $f->id,
            'numero_documento' => $f->numero_documento,
            'proveedor' => ['id' => (int) $f->proveedor_id, 'nombre' => $f->proveedor],
            'confirmado_at' => $f->confirmado_at,
            'confirmado_por' => $f->usuario_id ? ['id' => (int) $f->usuario_id, 'name' => $f->usuario] : null,
            'motivo' => $f->diferencia_motivo,
            'remisiones' => $remisiones->get($f->id, collect())->map(fn ($r) => ['id' => (int) $r->id, 'numero_documento' => $r->numero_documento])->values(),
            'detalle' => $recibido->get($f->id, collect())
                ->map(fn ($r) => [
                    'referencia_id' => (int) $r->referencia_id,
                    'codigo_referencia' => $codigos[$r->referencia_id] ?? null,
                    'recibido' => (int) $r->cantidad,
                    'facturado' => (int) ($facturado->get($f->id)?->get($r->referencia_id) ?? 0),
                ])
                ->filter(fn ($d) => $d['facturado'] < $d['recibido'])
                ->values(),
        ]);

        return ['filas' => $filas, 'totales' => ['facturas' => $filas->count()]];
    }

    /**
     * @param  Collection<int, object>  $titulares  por id
     * @param  Collection<int, Collection<int, array<string, mixed>>>  $documentos  por titular
     * @param  array<int, string>  $abonado  por titular
     * @return array{filas: Collection<int, array<string, mixed>>, totales: array<string, mixed>}
     */
    private function porTitular(Collection $titulares, Collection $documentos, array $abonado, CarbonInterface $corte, string $clave): array
    {
        $filas = $titulares
            ->map(fn ($t) => [
                $clave => $t->toArray(),
                ...Antiguedad::calcular($documentos->get($t->id, collect()), $abonado[$t->id] ?? '0', $corte)->resumen(),
            ])
            ->filter(fn ($f) => bccomp($f['saldo'], '0', self::ESCALA) !== 0)
            ->sortBy(fn ($f) => mb_strtolower($f[$clave]['nombre']))
            ->values();

        $rangos = array_keys(config('cartera.rangos'));
        $totales = $this->sumar($filas, ['cargos', 'abonos', 'saldo', 'a_favor', 'vencido']);
        $totales['rangos'] = collect($rangos)->mapWithKeys(fn ($r) => [
            $r => $filas->reduce(fn ($s, $f) => bcadd($s, $f['rangos'][$r], self::ESCALA), '0.00'),
        ])->all();

        return ['filas' => $filas, 'totales' => $totales];
    }

    /**
     * Abonos o pagos (crédito al saldo) y sus anulaciones (vuelven a sumar), hasta el fin.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function movimientosDinero(string $tabla, string $anulaciones, string $llave, string $titular, int $titularId, Carbon $fin, string $tipo): Collection
    {
        $medios = config('cartera.medios');
        $etiqueta = $tipo === 'abono' ? 'Abono' : 'Pago';

        $esPago = $tipo === 'pago';
        $movimientos = DB::table($tabla)
            ->when($esPago, fn ($q) => $q->leftJoin('ingresos', 'ingresos.id', '=', "{$tabla}.ingreso_id"))
            ->where("{$tabla}.{$titular}", $titularId)
            ->where("{$tabla}.fecha", '<=', $fin->toDateString())
            ->get([
                "{$tabla}.id", "{$tabla}.fecha", "{$tabla}.created_at", "{$tabla}.valor", "{$tabla}.medio", "{$tabla}.referencia",
                ...($esPago ? ['ingresos.tipo as doc_tipo', 'ingresos.numero_documento as doc_numero'] : []),
            ])
            ->map(fn ($m) => $this->movimiento($m->fecha, $tipo, trim("{$etiqueta} · ".($medios[$m->medio] ?? $m->medio).' '.($m->referencia ?? ''))
                // RF-17 (ajuste): el pago aplicado a una factura lo dice.
                .(isset($m->doc_numero) ? ' → '.$this->nombreDocumento($m->doc_tipo, $m->doc_numero) : ''), '0', $m->valor, [
                    "{$tipo}_id" => (int) $m->id,
                ], $m->created_at));

        $anulados = DB::table($anulaciones)
            ->join($tabla, "{$tabla}.id", '=', "{$anulaciones}.{$llave}")
            ->where("{$tabla}.{$titular}", $titularId)
            ->where("{$anulaciones}.created_at", '<=', $fin)
            ->get(["{$anulaciones}.created_at", "{$anulaciones}.motivo", "{$tabla}.id", "{$tabla}.valor", "{$tabla}.fecha"])
            ->map(fn ($a) => $this->movimiento($a->created_at, "anulacion_{$tipo}", "Anulación del {$tipo} del ".Carbon::parse($a->fecha)->format('d/m/Y').": {$a->motivo}", $a->valor, '0', [
                "{$tipo}_id" => (int) $a->id,
            ]));

        return $movimientos->concat($anulados);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $movimientos
     * @return array{saldo_anterior: string, filas: Collection<int, array<string, mixed>>, totales: array<string, string>}
     */
    private function estadoCuenta(Collection $movimientos, CarbonInterface $desde): array
    {
        $inicio = $desde->toDateString();
        $ordenados = $movimientos->sortBy(fn ($m) => [$m['fecha'], $m['_momento']])->values();

        $anterior = $ordenados->filter(fn ($m) => $m['fecha'] < $inicio)
            ->reduce(fn ($s, $m) => bcsub(bcadd($s, $m['cargo'], self::ESCALA), $m['abono'], self::ESCALA), '0.00');

        $saldo = $anterior;
        $filas = $ordenados->filter(fn ($m) => $m['fecha'] >= $inicio)->values()->map(function ($m) use (&$saldo) {
            $saldo = bcsub(bcadd($saldo, $m['cargo'], self::ESCALA), $m['abono'], self::ESCALA);
            unset($m['_momento']);

            return [...$m, 'saldo' => $saldo];
        });

        return [
            'saldo_anterior' => $anterior,
            'filas' => $filas,
            'totales' => [...$this->sumar($filas, ['cargo', 'abono']), 'saldo_final' => $saldo],
        ];
    }

    /** @param  array<string, mixed>  $extra */
    private function movimiento(string $cuando, string $tipo, string $detalle, string|int|float $cargo, string|int|float $abono, array $extra = [], ?string $momento = null): array
    {
        return [
            'fecha' => Carbon::parse($cuando)->toDateString(),
            'tipo' => $tipo,
            'detalle' => $detalle,
            'cargo' => bcadd((string) $cargo, '0', self::ESCALA),
            'abono' => bcadd((string) $abono, '0', self::ESCALA),
            ...$extra,
            '_momento' => $momento ?? $cuando,
        ];
    }

    private function nombreDocumento(string $tipo, string $numero): string
    {
        return ($tipo === TipoIngreso::Factura->value ? 'Factura ' : 'Remisión ').$numero;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $filas
     * @param  list<string>  $campos
     * @return array<string, string>
     */
    private function sumar(Collection $filas, array $campos): array
    {
        return collect($campos)->mapWithKeys(fn ($c) => [
            $c => $filas->reduce(fn ($s, $f) => $f[$c] === null ? $s : bcadd($s, (string) $f[$c], self::ESCALA), '0.00'),
        ])->all();
    }
}
