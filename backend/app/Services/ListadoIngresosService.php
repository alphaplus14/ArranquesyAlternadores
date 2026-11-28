<?php

namespace App\Services;

use App\Models\Ingreso;
use App\Models\Proveedor;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RF-04 / RF-05 (S-91): listado de Compras, solo consulta. Paginado en el servidor con orden estable
 * (fecha del documento y, a igualdad, id, los dos descendentes; índice `ingresos (fecha, id)`),
 * filtros por texto, borradores, corregidos, proveedor, mes o rango de fechas, y conteo por mes
 * para el selector "Mes". Sin consultas por fila.
 *
 * Filtros (todos opcionales): q, borradores (bool), corregidos (bool), proveedor_id, estado,
 * mes (YYYY-MM; si llega, se ignoran desde/hasta), desde, hasta.
 */
class ListadoIngresosService
{
    public function pagina(array $filtros, int $porPagina): LengthAwarePaginator
    {
        $pagina = $this->filtrar(Ingreso::query(), $filtros)
            ->with(['proveedor:id,nombre,asesor', 'usuario:id,name', 'confirmadoPor:id,name']) // S-29: asesor bajo el nombre
            ->withSum('items as unidades', 'cantidad')
            ->withCount('correcciones') // RF-28
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate($porPagina);
        $pagina->getCollection()->each(fn (Ingreso $i) => $i->setAttribute('corregido', $i->correcciones_count > 0));

        return $pagina;
    }

    /**
     * Meses con documentos, del más reciente al más antiguo: [{mes: '2026-11', total: 12}].
     * Respeta los demás filtros pero no el propio mes ni el rango. Una sola consulta agrupada.
     *
     * @return Collection<int, array{mes: string, total: int}>
     */
    public function meses(array $filtros): Collection
    {
        unset($filtros['mes'], $filtros['desde'], $filtros['hasta']);

        return $this->filtrar(Ingreso::query(), $filtros)
            ->toBase()
            ->selectRaw("DATE_FORMAT(fecha, '%Y-%m') as mes, COUNT(*) as total")
            ->groupBy(DB::raw("DATE_FORMAT(fecha, '%Y-%m')"))
            ->orderByDesc('mes')
            ->get()
            ->map(fn ($f) => ['mes' => $f->mes, 'total' => (int) $f->total]);
    }

    private function filtrar(Builder $q, array $f): Builder
    {
        $busqueda = trim((string) ($f['q'] ?? ''));

        return $q
            ->when($f['proveedor_id'] ?? null, fn ($q, $v) => $q->where('proveedor_id', $v))
            ->when(($f['borradores'] ?? false) ? 'borrador' : ($f['estado'] ?? null), fn ($q, $v) => $q->where('estado', $v))
            ->when($f['corregidos'] ?? false, fn ($q) => $q->has('correcciones'))
            ->when($busqueda !== '', fn ($q) => $this->buscar($q, $busqueda))
            ->when(
                ! empty($f['mes']),
                function ($q) use ($f) {
                    $inicio = CarbonImmutable::createFromFormat('!Y-m', $f['mes']);

                    return $q->whereBetween('fecha', [$inicio->toDateString(), $inicio->endOfMonth()->toDateString()]);
                },
                fn ($q) => $q
                    ->when($f['desde'] ?? null, fn ($q, $v) => $q->where('fecha', '>=', $v))
                    ->when($f['hasta'] ?? null, fn ($q, $v) => $q->where('fecha', '<=', $v)),
            );
    }

    /**
     * S-87: q = numero de documento (empieza por), nombre del proveedor (contiene) o NIT (comparado sin
     * puntos, guiones ni espacios; proveedores.nit se guarda tal como se digita). Sin N+1: una subconsulta.
     */
    private function buscar(Builder $q, string $texto): Builder
    {
        $nit = Proveedor::normalizarNit($texto);
        $like = fn (string $v) => addcslashes($v, '\%_');

        return $q->where(function (Builder $w) use ($texto, $nit, $like) {
            $w->where('numero_documento', 'like', $like($texto).'%')
                ->orWhereIn('proveedor_id', function ($sub) use ($texto, $nit, $like) {
                    $sub->select('id')->from('proveedores')->where(function ($p) use ($texto, $nit, $like) {
                        $p->where('nombre', 'like', '%'.$like($texto).'%');
                        if ($nit !== '') {
                            $p->orWhereRaw("LOWER(REPLACE(REPLACE(REPLACE(nit, '.', ''), '-', ''), ' ', '')) like ?", ['%'.$like($nit).'%']);
                        }
                    });
                });
        });
    }
}
