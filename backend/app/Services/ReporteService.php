<?php

namespace App\Services;

use App\Enums\EstadoIngreso;
use App\Enums\EstadoVenta;
use App\Enums\FormaPago;
use App\Enums\TipoIngreso;
use App\Models\Cliente;
use App\Models\IngresoItem;
use App\Models\Proveedor;
use App\Support\Antiguedad;
use App\Support\TotalesIngreso;
use App\Support\TotalesVenta;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     * 1. Compras por período (fecha del documento) y proveedor, de contado y a crédito. Una
     * remisión cubierta por una factura confirmada no cuenta: cuenta la factura (RF-19).
     *
     * @return array{filas: Collection<int, array<string, mixed>>, totales: array<string, string>}
     */
    public function compras(CarbonInterface $desde, CarbonInterface $hasta, ?int $proveedorId): array
    {
        $ingresos = $this->cartera->ingresosQueCuentan($proveedorId ? [$proveedorId] : null, Carbon::now())
            ->join('proveedores', 'proveedores.id', '=', 'ingresos.proveedor_id')
            ->whereBetween('ingresos.fecha', [$desde->toDateString(), $hasta->toDateString()])
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
     *
     * @return array{filas: Collection<int, array<string, mixed>>, totales: array<string, string>}
     */
    public function ventasCredito(CarbonInterface $desde, CarbonInterface $hasta, ?int $clienteId): array
    {
        $items = DB::table('venta_items')
            ->join('ventas', 'ventas.id', '=', 'venta_items.venta_id')
            ->leftJoin('reverso_items', 'reverso_items.venta_item_id', '=', 'venta_items.id')
            ->where('ventas.forma_pago', FormaPago::Credito->value)
            ->where('ventas.estado', EstadoVenta::Cerrada->value)
            ->whereBetween('ventas.cerrada_at', [Carbon::instance($desde)->startOfDay(), Carbon::instance($hasta)->endOfDay()])
            ->when($clienteId, fn ($q) => $q->where('ventas.cliente_id', $clienteId))
            ->whereNotNull('venta_items.precio_unitario')
            ->whereNull('reverso_items.id')
            ->get(['venta_items.venta_id', 'venta_items.precio_unitario', 'venta_items.iva_porcentaje'])
            ->groupBy('venta_id');

        $ventas = DB::table('ventas')
            ->join('clientes', 'clientes.id', '=', 'ventas.cliente_id')
            ->whereIn('ventas.id', $items->keys())
            ->orderBy('ventas.cerrada_at')->orderBy('ventas.id')
            ->get(['ventas.id', 'ventas.cerrada_at', 'ventas.vence_el', 'ventas.factura_contapyme', 'ventas.cliente_id', 'clientes.nombre as cliente']);

        $filas = $ventas->map(function ($v) use ($items) {
            $valor = TotalesVenta::desglosar($items[$v->id]->map(fn ($i) => [
                'precio' => $i->precio_unitario, 'iva' => $i->iva_porcentaje, 'vigente' => true,
            ]));

            return [
                'id' => (int) $v->id,
                'cliente' => ['id' => (int) $v->cliente_id, 'nombre' => $v->cliente],
                'fecha' => Carbon::parse($v->cerrada_at)->toDateString(),
                'vence_el' => $v->vence_el,
                'factura_contapyme' => $v->factura_contapyme,
                'items' => $items[$v->id]->count(),
                'base' => $valor->base,
                'iva' => $valor->ivaIncluido,
                'total' => $valor->total,
            ];
        });

        return ['filas' => $filas, 'totales' => $this->sumar($filas, ['base', 'iva', 'total'])];
    }

    /**
     * 3. Cartera de clientes a la fecha de corte: saldo por cliente con antigüedad.
     *
     * @return array{filas: Collection<int, array<string, mixed>>, totales: array<string, mixed>}
     */
    public function carteraClientes(CarbonInterface $corte): array
    {
        // Mismo cálculo que la pantalla de Cartera (CarteraService, S-30 / S-32).
        $filas = $this->cartera->resumenClientes($corte->toDateString())
            ->filter(fn ($f) => bccomp($f['saldo'], '0', self::ESCALA) !== 0)
            ->sortBy(fn ($f) => mb_strtolower($f['cliente']['nombre']))
            ->values();

        $totales = $this->sumar($filas, ['cargos', 'abonos', 'saldo', 'a_favor', 'vencido']);
        $totales['rangos'] = collect(array_keys(config('cartera.rangos')))->mapWithKeys(fn ($r) => [
            $r => $filas->reduce(fn ($s, $f) => bcadd($s, $f['rangos'][$r], self::ESCALA), '0.00'),
        ])->all();

        return ['filas' => $filas, 'totales' => $totales];
    }

    /**
     * 4. Cuentas por pagar a la fecha de corte: saldo por proveedor con antigüedad.
     *
     * @return array{filas: Collection<int, array<string, mixed>>, totales: array<string, mixed>}
     */
    public function cuentasPorPagar(CarbonInterface $corte): array
    {
        $documentos = $this->cartera->comprasCredito(null, $corte);
        $pagado = $this->cartera->pagado(null, $corte);
        $nombres = Proveedor::query()
            ->whereKey($documentos->keys()->merge(array_keys($pagado))->unique())
            ->get(['id', 'nombre', 'nit', 'plazo_dias'])->keyBy('id');

        return $this->porTitular($nombres, $documentos, $pagado, $corte, 'proveedor');
    }

    /**
     * 5. Estado de cuenta de un proveedor: ingresos a crédito confirmados, remisiones que una
     * factura confirmada cubrió (salen de la deuda ese día, RF-19), pagos y anulaciones de pagos.
     *
     * @return array{saldo_anterior: string, filas: Collection<int, array<string, mixed>>, totales: array<string, string>}
     */
    public function estadoCuentaProveedor(Proveedor $proveedor, CarbonInterface $desde, CarbonInterface $hasta): array
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

        return $this->estadoCuenta($movimientos, $desde);
    }

    /**
     * 6a. Remisiones confirmadas que todavía no cubre una factura confirmada, con su valor
     * provisional (los costos de la remisión) y su antigüedad en días.
     *
     * @return array{filas: Collection<int, array<string, mixed>>, totales: array<string, string>}
     */
    public function remisionesPendientes(?int $proveedorId): array
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

        $movimientos = DB::table($tabla)
            ->where($titular, $titularId)
            ->where('fecha', '<=', $fin->toDateString())
            ->get(['id', 'fecha', 'created_at', 'valor', 'medio', 'referencia'])
            ->map(fn ($m) => $this->movimiento($m->fecha, $tipo, trim("{$etiqueta} · ".($medios[$m->medio] ?? $m->medio).' '.($m->referencia ?? '')), '0', $m->valor, [
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
