<?php

namespace App\Services;

use App\Enums\EstadoIngreso;
use App\Enums\TipoIngreso;
use App\Models\CodigoUniversal;
use App\Models\Ingreso;
use App\Models\Referencia;
use App\Support\CostoEfectivo;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * S-71: trazabilidad del costo de compra por producto. Solo lectura: no escribe ni audita.
 *
 * - Una compra es una línea vigente (cantidad > 0, valores tras RF-28) de un ingreso CONFIRMADO,
 *   factura o remisión. Los borradores no cuentan.
 * - Costo neto unitario sin IVA = base de la línea ÷ cantidad, con CostoEfectivo (que sale de
 *   TotalesIngreso: descuento de línea y descuento del documento prorrateado, antes del IVA). Es la
 *   misma cifra del ingreso; aquí no se repite la fórmula.
 * - Orden: fecha del documento desc, luego id del ingreso desc (y de la línea). "Último" es la
 *   primera que cuenta y "anterior" la siguiente.
 * - No cuentan para último, anterior ni variación: las líneas con costo 0 ("Sin costo") y, por
 *   RF-19, la línea de una remisión cubierta por una factura CONFIRMADA que trae la misma
 *   referencia: el costo definitivo es el de la factura (el de la remisión es provisional y no se
 *   reajusta, adenda sección 6). En el historial se listan las dos, cada una con su marca.
 */
class CostoCompraService
{
    private const ESCALA = 10;

    /**
     * Último costo de cada referencia pedida, en lote: una consulta con ROW_NUMBER() sobre las
     * líneas de esas referencias y tres de CostoEfectivo, sin importar cuántas referencias.
     *
     * @param  list<int>  $referenciaIds
     * Sin fecha: Inventario muestra solo el valor; las fechas están en el historial (GET /costos).
     *
     * @return array<int, array{valor: string, ingreso_id: int, tipo: string, numero_documento: string}>
     */
    public function ultimosDe(array $referenciaIds): array
    {
        if ($referenciaIds === []) {
            return [];
        }

        $numeradas = $this->quecuentan($this->lineas()->whereIn('ii.referencia_id', $referenciaIds))
            ->selectRaw('ii.id as linea_id, ii.referencia_id, i.id as ingreso_id, i.fecha, i.tipo, i.numero_documento, i.descuento_documento')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY ii.referencia_id ORDER BY i.fecha DESC, i.id DESC, ii.id DESC) as n');
        $filas = DB::query()->fromSub($numeradas, 't')->where('n', 1)->get();
        if ($filas->isEmpty()) {
            return [];
        }
        // Todas las líneas de esos ingresos (el descuento del documento se prorratea entre ellas),
        // en filas planas: una consulta y el mismo cálculo de CostoEfectivo / TotalesIngreso.
        $items = DB::table('ingreso_items')
            ->whereIn('ingreso_id', $filas->pluck('ingreso_id')->unique()->values())
            ->get(['id', 'ingreso_id', 'cantidad', 'costo_unitario', 'descuento_porcentaje', 'iva_porcentaje'])
            ->groupBy('ingreso_id');
        $costos = CostoEfectivo::deIngresos($items, $filas->pluck('descuento_documento', 'ingreso_id')->all());

        return $filas->mapWithKeys(fn ($f) => [(int) $f->referencia_id => [
            'valor' => self::centavos($costos[(int) $f->linea_id] ?? '0'),
            'ingreso_id' => (int) $f->ingreso_id,
            'tipo' => (string) $f->tipo,
            'numero_documento' => (string) $f->numero_documento,
        ]])->all();
    }

    /**
     * El listado de Inventario recibe su último costo como relación cargada `ultimoCosto` (no es
     * una columna: no se desnormaliza en referencias). Solo este listado la carga; el escaneo de
     * venta, la búsqueda y las ventas nunca la llevan.
     *
     * @param  Collection<int, Referencia>  $referencias
     * @param  bool  $conDocumento  con `ver-historial-costos`: también ingreso, tipo y número (el enlace)
     */
    public function adjuntarUltimoCosto(Collection $referencias, bool $conDocumento): void
    {
        $ultimos = $this->ultimosDe($referencias->pluck('id')->map(fn ($id) => (int) $id)->all());
        foreach ($referencias as $referencia) {
            $costo = $ultimos[$referencia->id] ?? null;
            $referencia->setRelation('ultimoCosto', $costo === null || $conDocumento ? $costo : ['valor' => $costo['valor']]);
        }
    }

    /**
     * Historial de compras de una referencia o de todas las de un código universal (RF-02), más
     * reciente primero, con resumen (último, anterior, variación y compras registradas).
     *
     * @return array<string, mixed>
     */
    public function historial(?int $referenciaId, ?int $universalId, ?int $proveedorId, int $porPagina, int $pagina): array
    {
        if ($referenciaId !== null) {
            $referencia = Referencia::query()->with(['proveedor:id,nombre', 'codigoUniversal:id,codigo'])->findOrFail($referenciaId);
            $referenciaIds = [$referencia->id];
            $producto = ['tipo' => 'referencia', 'referencia' => [
                'id' => $referencia->id,
                'codigo_referencia' => $referencia->codigo_referencia,
                'descripcion' => $referencia->descripcion,
                'proveedor' => ['id' => $referencia->proveedor->id, 'nombre' => $referencia->proveedor->nombre],
                'calidad' => $referencia->calidad?->value,
                'existencia' => $referencia->existencia,
                'precio_venta' => $referencia->precio_venta,
                'activo' => $referencia->activo,
                'codigo_universal' => $referencia->codigoUniversal ? ['id' => $referencia->codigoUniversal->id, 'codigo' => $referencia->codigoUniversal->codigo] : null,
            ]];
        } else {
            $universal = CodigoUniversal::query()
                ->with(['referencias' => fn ($q) => $q->with('proveedor:id,nombre')->orderBy('codigo_referencia')])
                ->findOrFail($universalId);
            $referenciaIds = $universal->referencias->pluck('id')->all();
            $producto = ['tipo' => 'universal', 'universal' => [
                'id' => $universal->id,
                'codigo' => $universal->codigo,
                'descripcion' => $universal->descripcion,
                // RF-02: la existencia del universal es la suma de sus referencias activas.
                'existencia_total' => (int) $universal->referencias->where('activo', true)->sum('existencia'),
                'referencias' => $universal->referencias->map(fn (Referencia $r) => [
                    'id' => $r->id,
                    'codigo_referencia' => $r->codigo_referencia,
                    'proveedor' => ['id' => $r->proveedor->id, 'nombre' => $r->proveedor->nombre],
                    'calidad' => $r->calidad?->value,
                    'existencia' => $r->existencia,
                    'activo' => $r->activo,
                ])->values()->all(),
            ]];
        }

        $base = fn () => $this->lineas()
            ->whereIn('ii.referencia_id', $referenciaIds === [] ? [0] : $referenciaIds)
            ->when($proveedorId, fn ($q, $p) => $q->where('i.proveedor_id', $p));
        $orden = fn (Builder $q) => $q->orderByDesc('i.fecha')->orderByDesc('i.id')->orderByDesc('ii.id');

        /** @var LengthAwarePaginator $paginas */
        $paginas = $orden($base()
            ->join('referencias as r', 'r.id', '=', 'ii.referencia_id')
            ->join('proveedores as p', 'p.id', '=', 'i.proveedor_id')
            ->select([
                'ii.id as linea_id', 'ii.referencia_id', 'ii.cantidad', 'ii.costo_unitario', 'ii.descuento_porcentaje', 'ii.iva_porcentaje',
                'i.id as ingreso_id', 'i.fecha', 'i.tipo', 'i.numero_documento', 'i.factura_id', 'i.proveedor_id',
                'p.nombre as proveedor', 'r.codigo_referencia', 'r.calidad',
            ])
            ->selectRaw('EXISTS (SELECT 1 FROM ingreso_correcciones c WHERE c.ingreso_id = i.id) as corregida'))
            ->paginate($porPagina, ['*'], 'page', $pagina);
        $filas = $paginas->getCollection();
        $idsPagina = $filas->pluck('linea_id')->map(fn ($id) => (int) $id)->all();

        // Las que cuentan, en orden (solo ids): último, anterior y la compra anterior de cada fila.
        $cuentan = $orden($this->quecuentan($base()))->pluck('ii.id')->map(fn ($id) => (int) $id)->values()->all();
        $posicion = array_flip($cuentan);
        $previa = [];
        foreach ($idsPagina as $id) {
            if (isset($posicion[$id], $cuentan[$posicion[$id] + 1])) {
                $previa[$id] = $cuentan[$posicion[$id] + 1];
            }
        }
        $ultimoId = $cuentan[0] ?? null;
        $anteriorId = $cuentan[1] ?? null;
        $costos = CostoEfectivo::deLineas([...$idsPagina, ...array_values($previa), ...array_filter([$ultimoId, $anteriorId])]);

        // RF-19: qué filas de la página son remisiones cubiertas, y qué remisiones cubre cada factura.
        $cubiertas = $idsPagina === [] ? [] : array_flip(
            $this->lineas()->whereIn('ii.id', $idsPagina)->where(fn ($q) => $this->esRemisionCubierta($q))->pluck('ii.id')->map(fn ($id) => (int) $id)->all()
        );
        $facturaIds = $filas->filter(fn ($f) => $f->tipo === TipoIngreso::Factura->value)->pluck('ingreso_id')->unique()->values();
        $remisionesDe = $facturaIds->isEmpty() ? collect() : Ingreso::query()
            ->whereIn('factura_id', $facturaIds)->where('estado', EstadoIngreso::Confirmado)
            ->orderBy('numero_documento')->get(['id', 'factura_id', 'numero_documento'])->groupBy('factura_id');
        $numerosFactura = Ingreso::query()->whereKey($filas->pluck('factura_id')->filter()->unique()->values())
            ->where('estado', EstadoIngreso::Confirmado)->pluck('numero_documento', 'id');

        $paginas->setCollection($filas->map(function ($f) use ($costos, $previa, $ultimoId, $anteriorId, $cubiertas, $remisionesDe, $numerosFactura) {
            $id = (int) $f->linea_id;
            $sinCosto = bccomp((string) $f->costo_unitario, '0', 2) <= 0;
            $neto = $sinCosto || ! isset($costos[$id]) ? null : $costos[$id];
            $cubierta = isset($cubiertas[$id]);

            return [
                'linea_id' => $id,
                    'ingreso_id' => (int) $f->ingreso_id,
                'tipo' => $f->tipo,
                'numero_documento' => $f->numero_documento,
                'proveedor' => ['id' => (int) $f->proveedor_id, 'nombre' => $f->proveedor],
                'referencia' => ['id' => (int) $f->referencia_id, 'codigo_referencia' => $f->codigo_referencia, 'calidad' => $f->calidad],
                'cantidad' => (int) $f->cantidad,
                'costo_factura' => (string) $f->costo_unitario,
                'descuento_porcentaje' => (string) $f->descuento_porcentaje,
                'iva_porcentaje' => (string) $f->iva_porcentaje,
                'costo_neto' => $neto === null ? null : self::centavos($neto),
                'costo_con_iva' => $neto === null ? null : self::centavos(bcmul($neto, bcadd('1', bcdiv((string) $f->iva_porcentaje, '100', self::ESCALA), self::ESCALA), self::ESCALA)),
                'variacion' => isset($previa[$id], $costos[$id], $costos[$previa[$id]]) ? self::variacion($costos[$id], $costos[$previa[$id]]) : null,
                'ultimo' => $id === $ultimoId,
                'anterior' => $id === $anteriorId,
                'sin_costo' => $sinCosto,
                'corregida' => (bool) $f->corregida,
                // RF-19: remisión cubierta (su costo no cuenta) / factura que cubre remisiones.
                'cubierta_por' => $cubierta ? ($numerosFactura[(int) $f->factura_id] ?? null) : null,
                'cubre_remisiones' => $remisionesDe->get((int) $f->ingreso_id, collect())->pluck('numero_documento')->values()->all(),
            ];
        }));

        $documentos = DB::table('ingreso_items as ii')->join('ingresos as i', 'i.id', '=', 'ii.ingreso_id')
            ->whereIn('ii.id', array_filter([$ultimoId, $anteriorId]) ?: [0])
            ->get(['ii.id', 'i.id as ingreso_id', 'i.fecha', 'i.tipo', 'i.numero_documento'])->keyBy('id');
        $compra = fn (?int $linea) => $linea === null ? null : [
            'valor' => self::centavos($costos[$linea] ?? '0'),
            'fecha' => substr((string) $documentos[$linea]->fecha, 0, 10),
            'ingreso_id' => (int) $documentos[$linea]->ingreso_id,
            'tipo' => $documentos[$linea]->tipo,
            'numero_documento' => $documentos[$linea]->numero_documento,
        ];

        return [
            ...$paginas->toArray(),
            'producto' => $producto,
            'resumen' => [
                'ultimo' => $compra($ultimoId),
                'anterior' => $compra($anteriorId),
                'variacion' => $ultimoId !== null && $anteriorId !== null && isset($costos[$ultimoId], $costos[$anteriorId])
                    ? self::variacion($costos[$ultimoId], $costos[$anteriorId]) : null,
                // Compras distintas: sin la remisión cubierta (es la misma compra que su factura).
                'compras' => $this->sinRemisionCubierta($base())->count(),
            ],
        ];
    }

    /** Líneas vigentes de ingresos confirmados (los valores ya son los corregidos por RF-28). */
    private function lineas(): Builder
    {
        return DB::table('ingreso_items as ii')
            ->join('ingresos as i', 'i.id', '=', 'ii.ingreso_id')
            ->where('i.estado', EstadoIngreso::Confirmado->value)
            ->where('ii.cantidad', '>', 0);
    }

    /** Las que cuentan para último, anterior y variación: con costo y sin la remisión cubierta. */
    private function quecuentan(Builder $q): Builder
    {
        return $this->sinRemisionCubierta($q->where('ii.costo_unitario', '>', 0));
    }

    private function sinRemisionCubierta(Builder $q): Builder
    {
        return $q->whereNot(fn ($w) => $this->esRemisionCubierta($w));
    }

    /** RF-19: línea de una remisión ligada a una factura confirmada que trae la misma referencia. */
    private function esRemisionCubierta(Builder $q): Builder
    {
        return $q->where('i.tipo', TipoIngreso::Remision->value)
            ->whereNotNull('i.factura_id')
            ->whereExists(fn ($e) => $e->selectRaw('1')
                ->from('ingresos as f')
                ->join('ingreso_items as fi', 'fi.ingreso_id', '=', 'f.id')
                ->whereColumn('f.id', 'i.factura_id')
                ->where('f.estado', EstadoIngreso::Confirmado->value)
                ->whereColumn('fi.referencia_id', 'ii.referencia_id')
                ->where('fi.cantidad', '>', 0));
    }

    /** % de cambio de `$nuevo` frente a `$anterior`, a un decimal (bcmath). null si el anterior es 0. */
    private static function variacion(string $nuevo, string $anterior): ?string
    {
        if (bccomp($anterior, '0', self::ESCALA) === 0) {
            return null;
        }
        $pct = bcmul(bcdiv(bcsub($nuevo, $anterior, self::ESCALA), $anterior, self::ESCALA), '100', self::ESCALA);

        return bcadd($pct, str_starts_with($pct, '-') ? '-0.05' : '0.05', 1);
    }

    /** Al centavo, mitad hacia arriba (como las cifras que muestra TotalesIngreso). */
    private static function centavos(string $valor): string
    {
        return bcadd($valor, str_starts_with($valor, '-') ? '-0.005' : '0.005', 2);
    }
}
