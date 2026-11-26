<?php

namespace App\Services;

use App\Enums\Rol;
use App\Http\Resources\ReferenciaResource;
use App\Models\CodigoUniversal;
use App\Models\Referencia;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * S-82 (RF-01, RF-02, RF-11, RF-22; reglas 2 y 12): listado de Inventario con los códigos universales
 * integrados. Cada ítem es un UNIVERSAL (fila agrupadora con sus referencias anidadas) o una
 * REFERENCIA SIN universal; una referencia con universal nunca sale suelta.
 *
 * - Orden por código (del universal o de la referencia), luego tipo e id: determinista entre páginas.
 * - Paginación en el servidor sobre un UNION ALL de las dos clases de ítem (cada mitad usa los
 *   índices de su tabla); luego, para la página, una consulta de universales y UNA de referencias
 *   (las sueltas y las hijas juntas), más una de coincidencias si hay búsqueda: número fijo de
 *   consultas, sin N+1.
 * - Filtros: inactivas (admin y logística, S-26), proveedor (el universal sale si alguna de sus
 *   referencias es del proveedor y en el desglose solo van las de ese proveedor), categoría, tipo
 *   (`universales` | `sin_universal`) y `universal_id` (abre un grupo, p. ej. desde /universales/:id).
 * - Búsqueda `q`: la de referencias (código, código de fabricante, descripción; RF-12) y además el
 *   código (prefijo) o la descripción del universal. Si coincide una referencia hija, sale su
 *   universal con `coincidencias` (ids de las hijas que coincidieron, para resaltarlas).
 * - Existencia del universal (RF-02) = suma de sus referencias visibles (las activas; con "ver
 *   inactivas", también las inactivas), de todos los proveedores. El bajo mínimo (RF-22) se evalúa,
 *   como siempre, sobre la suma de las activas (StockMinimoService). A mostrador no se le envían
 *   mínimos (S-28) ni costos (S-71, según su permiso).
 */
class InventarioListaService
{
    public function __construct(
        private readonly StockMinimoService $minimos,
        private readonly CostoCompraService $costos,
    ) {}

    /**
     * @param  array{q?: ?string, proveedor_id?: ?int, categoria_id?: ?int, tipo?: ?string, universal_id?: ?int, inactivas?: bool}  $filtros
     * @return array<string, mixed>
     */
    public function listar(array $filtros, User $usuario, Request $peticion, int $porPagina, int $pagina): array
    {
        $q = trim((string) ($filtros['q'] ?? ''));
        $conInactivas = ($filtros['inactivas'] ?? false) && in_array($usuario->rol, [Rol::Admin, Rol::Logistica], true);
        $proveedorId = $filtros['proveedor_id'] ?? null;
        $categoriaId = $filtros['categoria_id'] ?? null;
        $universalId = $filtros['universal_id'] ?? null;
        $tipo = $filtros['tipo'] ?? null;

        /** Filtros comunes sobre `referencias` (sin la búsqueda). */
        $filtrosReferencia = fn (EloquentBuilder|Builder $r) => $r
            ->when(! $conInactivas, fn ($x) => $x->where('referencias.activo', true))
            ->when($proveedorId, fn ($x, $v) => $x->where('referencias.proveedor_id', $v))
            ->when($categoriaId, fn ($x, $v) => $x->where('referencias.categoria_id', $v));

        $partes = [];
        if ($universalId === null && $tipo !== 'universales') {
            $partes[] = Referencia::query()
                ->selectRaw("'referencia' as tipo, referencias.id, referencias.codigo_referencia as orden")
                ->whereNull('referencias.codigo_universal_id')
                ->tap($filtrosReferencia)
                ->when($q !== '', fn ($x) => $x->buscarTexto($q)->reorder())
                ->toBase();
        }
        if ($tipo !== 'sin_universal') {
            $partes[] = $this->consultaUniversales($q, $universalId, $categoriaId, $proveedorId, $filtrosReferencia);
        }

        $union = array_shift($partes);
        foreach ($partes as $parte) {
            $union->unionAll($parte);
        }
        $paginador = DB::query()->fromSub($union, 'items')
            ->orderBy('orden')->orderBy('tipo')->orderBy('id')
            ->paginate($porPagina, ['tipo', 'id'], 'page', $pagina);

        $items = $paginador->getCollection();
        $idsSueltas = $items->where('tipo', 'referencia')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $idsUniversales = $items->where('tipo', 'universal')->pluck('id')->map(fn ($id) => (int) $id)->all();

        $universales = $idsUniversales === [] ? collect() : CodigoUniversal::query()
            ->with('categoria:id,nombre,admite_codigo_universal')
            ->whereIn('id', $idsUniversales)
            ->get()->keyBy('id');

        // Una sola consulta: las referencias sueltas de la página y todas las hijas de sus universales.
        $referencias = ($idsSueltas === [] && $idsUniversales === []) ? collect() : Referencia::query()
            ->with(['proveedor:id,nombre', 'categoria:id,nombre,admite_codigo_universal'])
            ->where(fn ($w) => $w
                ->whereIn('id', $idsSueltas === [] ? [0] : $idsSueltas)
                ->orWhere(fn ($h) => $h
                    ->whereIn('codigo_universal_id', $idsUniversales === [] ? [0] : $idsUniversales)
                    ->when(! $conInactivas, fn ($x) => $x->where('activo', true))))
            ->orderBy('codigo_referencia')->orderBy('id')
            ->get();
        $hijas = $referencias->whereNotNull('codigo_universal_id')->groupBy('codigo_universal_id');
        $sueltas = $referencias->whereNull('codigo_universal_id')->keyBy('id');

        $coincidencias = $q === '' || $idsUniversales === [] ? collect() : Referencia::query()
            ->whereIn('referencias.codigo_universal_id', $idsUniversales)
            ->tap($filtrosReferencia)
            ->buscarTexto($q)->reorder()
            ->get(['referencias.id', 'referencias.codigo_universal_id'])
            ->groupBy('codigo_universal_id')
            ->map(fn (Collection $c) => $c->pluck('id')->map(fn ($id) => (int) $id)->values()->all());

        // RF-22: cada hija lleva su universal con la suma de las activas, para que ReferenciaResource
        // evalúe el bajo mínimo igual que en el resto del sistema.
        $sumasActivas = [];
        foreach ($universales as $id => $universal) {
            $sumasActivas[$id] = (int) ($hijas->get($id, collect())->where('activo', true)->sum('existencia'));
            $universal->setAttribute('existencia_total', $sumasActivas[$id]);
            foreach ($hijas->get($id, collect()) as $hija) {
                $hija->setRelation('codigoUniversal', $universal);
            }
        }

        // S-71: último costo de toda la página en lote, solo con `ver-ultimo-costo`.
        $visibles = $sueltas->values()->concat($hijas->flatten(1)
            ->filter(fn (Referencia $r) => ! $proveedorId || (int) $r->proveedor_id === (int) $proveedorId));
        if ($usuario->can('ver-ultimo-costo')) {
            $this->costos->adjuntarUltimoCosto($visibles, conDocumento: $usuario->can('ver-historial-costos'));
        }

        $verMinimo = $usuario->rol !== Rol::Mostrador;
        $datos = $items->map(function ($item) use ($universales, $hijas, $sueltas, $coincidencias, $sumasActivas, $proveedorId, $verMinimo, $peticion) {
            if ($item->tipo === 'referencia') {
                $r = $sueltas->get((int) $item->id);

                return $r ? ['tipo' => 'referencia', ...(new ReferenciaResource($r))->resolve($peticion)] : null;
            }
            $u = $universales->get((int) $item->id);
            if (! $u) {
                return null;
            }
            $todas = $hijas->get($u->id, collect());
            $mostradas = $proveedorId ? $todas->filter(fn (Referencia $r) => (int) $r->proveedor_id === (int) $proveedorId) : $todas;
            $minimo = $this->minimos->efectivo($u->stock_minimo);

            $fila = [
                'tipo' => 'universal',
                'id' => $u->id,
                'codigo' => $u->codigo,
                'descripcion' => $u->descripcion ?? $todas->first()?->descripcion,
                'categoria_id' => $u->categoria_id,
                'categoria' => $u->categoria ? ['id' => $u->categoria->id, 'nombre' => $u->categoria->nombre] : null,
                // RF-02: suma de sus referencias visibles, de todos los proveedores.
                'existencia_total' => (int) $todas->sum('existencia'),
                'cantidad_referencias' => $todas->count(),
                'proveedores' => $todas->map(fn (Referencia $r) => ['id' => $r->proveedor->id, 'nombre' => $r->proveedor->nombre])
                    ->unique('id')->sortBy('nombre')->values()->all(),
                'coincidencias' => $coincidencias->get($u->id, []),
                'referencias' => $mostradas->values()->map(fn (Referencia $r) => (new ReferenciaResource($r))->resolve($peticion))->all(),
            ];
            // RF-22 / S-28: el mínimo del universal, sobre la suma de sus activas; nunca a mostrador.
            if ($verMinimo) {
                $fila += [
                    'stock_minimo' => $u->stock_minimo,
                    'minimo_efectivo' => $minimo['minimo'],
                    'minimo_origen' => $minimo['origen'],
                    'bajo_minimo' => $minimo['minimo'] !== null && $todas->where('activo', true)->isNotEmpty() && $sumasActivas[$u->id] <= $minimo['minimo'],
                ];
            }

            return $fila;
        })->filter()->values()->all();

        return [...$paginador->toArray(), 'data' => $datos];
    }

    /**
     * Universales que entran a la lista: con alguna referencia que cumpla los filtros (y la búsqueda),
     * o que coinciden ellos mismos por código o descripción. Un universal todavía sin referencias
     * sale solo sin filtro de proveedor (no tiene ninguna de él).
     */
    private function consultaUniversales(string $q, ?int $universalId, ?int $categoriaId, ?int $proveedorId, \Closure $filtrosReferencia): Builder
    {
        $conReferencia = fn (bool $conBusqueda) => fn (Builder $e) => $e->selectRaw('1')
            ->from('referencias')
            ->whereColumn('referencias.codigo_universal_id', 'u.id')
            ->tap($filtrosReferencia)
            ->when($conBusqueda, fn (Builder $x) => $x->whereIn('referencias.id', Referencia::query()->select('referencias.id')->buscarTexto($q)->reorder()->toBase()));
        $sinReferencias = fn (Builder $w) => $w->whereNotExists(fn (Builder $e) => $e->selectRaw('1')->from('referencias')->whereColumn('referencias.codigo_universal_id', 'u.id'));
        $propioUniversal = fn (Builder $w) => $w->where('u.codigo', 'like', addcslashes($q, '%_\\').'%')
            ->orWhere('u.descripcion', 'like', '%'.addcslashes($q, '%_\\').'%');

        return DB::table('codigos_universales as u')
            ->selectRaw("'universal' as tipo, u.id, u.codigo as orden")
            ->when($categoriaId, fn (Builder $x, $v) => $x->where('u.categoria_id', $v))
            ->when($universalId !== null, fn (Builder $x) => $x->where('u.id', $universalId), function (Builder $x) use ($q, $proveedorId, $conReferencia, $sinReferencias, $propioUniversal) {
                $visible = fn (Builder $w) => $w->whereExists($conReferencia(false))
                    ->when(! $proveedorId, fn (Builder $o) => $o->orWhere($sinReferencias));
                if ($q === '') {
                    return $x->where($visible);
                }

                return $x->where(fn (Builder $w) => $w
                    ->whereExists($conReferencia(true))
                    ->orWhere(fn (Builder $p) => $p->where($propioUniversal)->where($visible)));
            });
    }
}
