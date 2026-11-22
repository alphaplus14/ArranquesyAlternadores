<?php

namespace App\Services;

use App\Enums\EstadoUnidad;
use App\Models\CodigoUniversal;
use App\Models\Referencia;
use App\Models\Unidad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * RF-12: respaldo cuando el escáner no lee. Solo lectura.
 *
 * Orden de los resultados:
 *  1. Código de barras exacto → la unidad.
 *  2. Referencias cuyo código de referencia o código universal empieza por el término.
 *  3. Referencias cuya descripción contiene todas las palabras (FULLTEXT, por relevancia).
 * Dentro de cada grupo van primero las que tienen existencia: el objetivo es vender.
 * Sin LIKE con comodín inicial: la velocidad es requisito.
 */
class BusquedaService
{
    public const MAX_REFERENCIAS = 20;

    public const MAX_UNIDADES_POR_REFERENCIA = 20;

    /** @return array{unidad: ?Unidad, referencias: Collection<int, Referencia>} */
    public function buscar(string $q): array
    {
        $unidad = Unidad::query()
            ->where('codigo_barras', $q)
            ->with('referencia.marca:id,nombre', 'referencia.proveedor:id,nombre', 'referencia.codigoUniversal:id,codigo')
            ->first();

        $porCodigo = $this->porCodigo($q);

        // Un código de barras exacto ya respondió: no se mezcla con descripciones.
        $faltan = self::MAX_REFERENCIAS - $porCodigo->count();
        $porDescripcion = $unidad === null && $faltan > 0
            ? $this->conExistenciaPrimero(Referencia::query()->whereKeyNot($porCodigo->modelKeys()))
                ->buscarTexto($q)
                ->limit($faltan)
                ->get()
            : new Collection;

        $referencias = $porCodigo->concat($porDescripcion)->load([
            'marca:id,nombre',
            'proveedor:id,nombre',
            'codigoUniversal:id,codigo',
            // Laravel limita por referencia con funciones de ventana (MySQL 8).
            'unidades' => fn ($u) => $u
                ->where('estado', EstadoUnidad::Disponible)
                ->orderBy('id')
                ->limit(self::MAX_UNIDADES_POR_REFERENCIA)
                ->select('id', 'referencia_id', 'codigo_barras', 'estado'),
        ]);

        return ['unidad' => $unidad, 'referencias' => $referencias];
    }

    /** @return Collection<int, Referencia> */
    private function porCodigo(string $q): Collection
    {
        $prefijo = addcslashes($q, '%_\\').'%';
        $universales = CodigoUniversal::where('codigo', 'like', $prefijo)->limit(self::MAX_REFERENCIAS)->pluck('id');

        return $this->conExistenciaPrimero(Referencia::query())
            ->where(fn ($w) => $w
                ->where('codigo_referencia', 'like', $prefijo)
                ->when($universales->isNotEmpty(), fn ($w) => $w->orWhereIn('codigo_universal_id', $universales)))
            ->orderBy('codigo_referencia')
            ->limit(self::MAX_REFERENCIAS)
            ->get();
    }

    /** Va antes de cualquier otro orden, para que este quede como criterio principal. */
    private function conExistenciaPrimero(Builder $query): Builder
    {
        return $query->orderByRaw('existencia > 0 DESC');
    }
}
