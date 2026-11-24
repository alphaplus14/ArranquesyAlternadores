<?php

namespace App\Services;

use App\Models\Configuracion;
use App\Models\Referencia;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * RF-22 (S-28): stock mínimo. Solo informa: no escribe existencias ni crea pedidos.
 *
 * Mínimo efectivo de un producto (referencia sin universal, o el universal):
 * - stock_minimo NULL -> el general (si el general es NULL o 0, no hay mínimo);
 * - stock_minimo 0    -> el producto no maneja mínimo (nunca alerta);
 * - stock_minimo > 0  -> ese valor (personalizado).
 * Está "bajo mínimo" cuando existencia <= mínimo (con mínimo > 0). Una referencia de un universal
 * se evalúa con el mínimo del universal y la SUMA de sus referencias activas (RF-02).
 * Las referencias desactivadas no se evalúan ni suman; las unidades retiradas ya no cuentan en la
 * existencia (RF-21). La regla vive en efectivo() y en minimoSql(): la misma, en PHP y en SQL.
 */
class StockMinimoService
{
    public const MAXIMO = 9999;

    public const GENERAL = 'general';

    public const PERSONALIZADO = 'personalizado';

    /** Valor guardado del general (puede ser 0 o null). */
    public function valorGeneral(): ?int
    {
        // Se lee una vez por petición: el listado lo pide por cada fila.
        $peticion = request();
        if (! $peticion->attributes->has('stock_minimo_general')) {
            $valor = Configuracion::where('clave', Configuracion::STOCK_MINIMO_GENERAL)->value('valor');
            $peticion->attributes->set('stock_minimo_general', $valor === null ? null : (int) $valor);
        }

        return $peticion->attributes->get('stock_minimo_general');
    }

    /** Solo el admin (lo protege la ruta). Queda auditado por el trait Auditable (RF-11). */
    public function fijarGeneral(?int $valor): ?int
    {
        Configuracion::firstOrCreate(['clave' => Configuracion::STOCK_MINIMO_GENERAL])
            ->update(['valor' => $valor === null ? null : (string) $valor]);

        request()->attributes->set('stock_minimo_general', $valor);

        return $valor;
    }

    /**
     * @return array{minimo: int|null, origen: string|null} minimo null = no maneja mínimo
     */
    public function efectivo(?int $propio): array
    {
        if ($propio === null) {
            $general = $this->valorGeneral();

            return $general > 0 ? ['minimo' => $general, 'origen' => self::GENERAL] : ['minimo' => null, 'origen' => null];
        }

        return $propio > 0 ? ['minimo' => $propio, 'origen' => self::PERSONALIZADO] : ['minimo' => null, 'origen' => null];
    }

    /**
     * Evaluación de una referencia para el listado. Si pertenece a un universal, necesita la
     * relación codigoUniversal cargada con `existencia_total` (suma de activas); si no la tiene,
     * devuelve null (no se puede afirmar nada sin la suma).
     *
     * @return array{stock_minimo: int|null, minimo_efectivo: int|null, origen: string|null, existencia_evaluada: int, bajo_minimo: bool}|null
     */
    public function evaluarReferencia(Referencia $referencia): ?array
    {
        if ($referencia->codigo_universal_id !== null) {
            $universal = $referencia->relationLoaded('codigoUniversal') ? $referencia->codigoUniversal : null;
            if (! $universal || ! array_key_exists('existencia_total', $universal->getAttributes())) {
                return null;
            }
            $propio = $universal->stock_minimo;
            $existencia = (int) $universal->existencia_total;
        } else {
            $propio = $referencia->stock_minimo;
            $existencia = (int) $referencia->existencia;
        }

        ['minimo' => $minimo, 'origen' => $origen] = $this->efectivo($propio);

        return [
            'stock_minimo' => $propio,
            'minimo_efectivo' => $minimo,
            'origen' => $origen,
            'existencia_evaluada' => $existencia,
            'bajo_minimo' => $referencia->activo !== false && $minimo !== null && $existencia <= $minimo,
        ];
    }

    /**
     * Informe "Bajo mínimo": referencias sin universal y universales, ordenados por mayor
     * faltante. Todo en SQL y paginado; sin LIKE. Filtro por proveedor: la referencia es de ese
     * proveedor o el universal tiene una referencia activa de él (la suma sigue siendo de todas).
     *
     * @param  array{proveedor_id?: int|string|null, categoria_id?: int|string|null}  $filtros
     */
    public function bajoMinimo(array $filtros, int $porPagina = 50): LengthAwarePaginator
    {
        $general = max(0, (int) $this->valorGeneral());
        $proveedorId = $filtros['proveedor_id'] ?? null;
        $categoriaId = $filtros['categoria_id'] ?? null;

        // Tope: nadie puede estar bajo mínimo con existencia mayor al mayor mínimo posible.
        // Con él, (activo, existencia) acota las referencias por rango (MAX usa el índice).
        $tope = max($general, (int) DB::table('referencias')->max('stock_minimo'));

        $referencias = DB::table('referencias as r')
            ->join('proveedores as p', 'p.id', '=', 'r.proveedor_id')
            ->join('categorias as c', 'c.id', '=', 'r.categoria_id')
            ->selectRaw("'referencia' as tipo, r.id, r.codigo_referencia as codigo, r.descripcion, r.calidad, r.proveedor_id, p.nombre as proveedor, r.categoria_id, c.nombre as categoria, r.existencia, {$this->minimoSql('r')} as minimo, {$this->origenSql('r')} as origen", [$general])
            ->where('r.activo', true)
            ->where('r.existencia', '<=', $tope)
            ->whereNull('r.codigo_universal_id')
            ->whereRaw("{$this->minimoSql('r')} > 0", [$general])
            ->whereRaw("r.existencia <= {$this->minimoSql('r')}", [$general])
            ->when($proveedorId, fn ($q, $v) => $q->where('r.proveedor_id', $v))
            ->when($categoriaId, fn ($q, $v) => $q->where('r.categoria_id', $v));

        $universales = DB::table('codigos_universales as u')
            ->join('referencias as r', fn ($j) => $j->on('r.codigo_universal_id', '=', 'u.id')->where('r.activo', true))
            ->join('categorias as c', 'c.id', '=', 'u.categoria_id')
            ->selectRaw("'universal' as tipo, u.id, u.codigo, COALESCE(u.descripcion, MIN(r.descripcion)) as descripcion, NULL as calidad, NULL as proveedor_id, NULL as proveedor, u.categoria_id, c.nombre as categoria, SUM(r.existencia) as existencia, {$this->minimoSql('u')} as minimo, {$this->origenSql('u')} as origen", [$general])
            ->whereRaw("{$this->minimoSql('u')} > 0", [$general])
            ->when($categoriaId, fn ($q, $v) => $q->where('u.categoria_id', $v))
            ->when($proveedorId, fn ($q, $v) => $q->whereExists(fn (Builder $e) => $e
                ->selectRaw('1')->from('referencias as rp')
                ->whereColumn('rp.codigo_universal_id', 'u.id')
                ->where('rp.activo', true)
                ->where('rp.proveedor_id', $v)))
            ->groupBy('u.id', 'u.codigo', 'u.descripcion', 'u.categoria_id', 'c.nombre', 'u.stock_minimo')
            ->havingRaw('SUM(r.existencia) <= MAX('.$this->minimoSql('u').')', [$general]);

        $pagina = DB::query()
            ->fromSub($referencias->unionAll($universales), 'b')
            ->selectRaw('b.*, GREATEST(b.minimo - b.existencia, 0) as faltante')
            ->orderByDesc('faltante')
            ->orderBy('codigo')
            ->orderBy('tipo')
            ->paginate($porPagina);

        $desglose = $this->desglose($pagina->getCollection()->where('tipo', 'universal')->pluck('id')->all());

        return $pagina->through(fn ($f) => [
            'tipo' => $f->tipo,
            'id' => (int) $f->id,
            'codigo' => $f->codigo,
            'descripcion' => $f->descripcion,
            'calidad' => $f->calidad,
            'proveedor' => $f->proveedor_id ? ['id' => (int) $f->proveedor_id, 'nombre' => $f->proveedor] : null,
            'categoria' => ['id' => (int) $f->categoria_id, 'nombre' => $f->categoria],
            'existencia' => (int) $f->existencia,
            'minimo' => (int) $f->minimo,
            'origen' => $f->origen,
            'faltante' => (int) $f->faltante,
            'referencias' => $f->tipo === 'universal' ? ($desglose[$f->id] ?? []) : null,
        ]);
    }

    /** Mínimo efectivo en SQL (0 = sin mínimo). Un placeholder: el general. */
    private function minimoSql(string $t): string
    {
        return "(CASE WHEN {$t}.stock_minimo IS NULL THEN ? ELSE {$t}.stock_minimo END)";
    }

    private function origenSql(string $t): string
    {
        return "(CASE WHEN {$t}.stock_minimo IS NULL THEN '".self::GENERAL."' ELSE '".self::PERSONALIZADO."' END)";
    }

    /**
     * RF-02: desglose por referencia activa de los universales de la página, en una consulta.
     *
     * @param  list<int>  $ids
     * @return array<int, list<array<string, mixed>>>
     */
    private function desglose(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('referencias as r')
            ->join('proveedores as p', 'p.id', '=', 'r.proveedor_id')
            ->whereIn('r.codigo_universal_id', $ids)
            ->where('r.activo', true)
            ->orderBy('r.codigo_referencia')
            ->get(['r.id', 'r.codigo_universal_id', 'r.codigo_referencia', 'r.calidad', 'r.existencia', 'p.id as proveedor_id', 'p.nombre as proveedor'])
            ->groupBy('codigo_universal_id')
            ->map(fn ($filas) => $filas->map(fn ($r) => [
                'id' => (int) $r->id,
                'codigo_referencia' => $r->codigo_referencia,
                'calidad' => $r->calidad,
                'proveedor' => ['id' => (int) $r->proveedor_id, 'nombre' => $r->proveedor],
                'existencia' => (int) $r->existencia,
            ])->values()->all())
            ->all();
    }
}
