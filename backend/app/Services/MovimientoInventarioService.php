<?php

namespace App\Services;

use App\Models\Ingreso;
use App\Models\IngresoCorreccion;
use App\Models\MovimientoInventario;
use App\Models\Referencia;
use App\Support\RangoFechas;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * RF-21 (S-87): historial de movimientos de inventario por producto (Logística → Movimientos),
 * con las entradas por compras confirmadas. Solo lectura. Las etiquetas del origen se resuelven
 * en bloque (una sola consulta de ingresos por página), nunca por fila.
 */
class MovimientoInventarioService
{
    private const POR_PAGINA = 20; // S-70: igual que el historial de ajustes

    /** @param array<string, mixed> $filtros */
    public function historial(array $filtros): LengthAwarePaginator
    {
        $referenciaIds = $this->referenciasDeBusqueda($filtros['q'] ?? null);

        $pagina = MovimientoInventario::query()
            ->with([
                'referencia:id,codigo_referencia,descripcion,calidad,proveedor_id',
                'referencia.proveedor:id,nombre',
                'usuario:id,name',
                'unidad:id,codigo_barras',
            ])
            ->when($filtros['referencia_id'] ?? null, fn ($q, $v) => $q->where('referencia_id', $v))
            ->when($filtros['universal_id'] ?? null, fn ($q, $v) => $q->whereIn('referencia_id',
                Referencia::query()->where('codigo_universal_id', $v)->select('id')))
            ->when($referenciaIds !== null, fn ($q) => $q->whereIn('referencia_id', $referenciaIds))
            ->when($filtros['proveedor_id'] ?? null, fn ($q, $v) => $q->whereIn('referencia_id',
                Referencia::query()->where('proveedor_id', $v)->select('id')))
            ->when($filtros['tipo'] ?? null, fn ($q, $v) => $q->where('tipo', $v))
            ->when($filtros['metodo_captura'] ?? null, fn ($q, $v) => $q->where('metodo_captura', $v))
            ->when($filtros['usuario_id'] ?? null, fn ($q, $v) => $q->where('usuario_id', $v))
            // Días locales (Bogotá) convertidos a UTC; "hasta" es inclusivo del día completo.
            ->when($filtros['desde'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', RangoFechas::inicio($v)))
            ->when($filtros['hasta'] ?? null, fn ($q, $v) => $q->where('created_at', '<', RangoFechas::finExclusivo($v)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($filtros['por_pagina'] ?? self::POR_PAGINA));

        $this->resolverEtiquetas($pagina->getCollection());

        return $pagina;
    }

    /** @return list<int>|null null = sin búsqueda por texto */
    private function referenciasDeBusqueda(?string $q): ?array
    {
        if ($q === null || $q === '') {
            return null;
        }
        $like = '%'.addcslashes($q, '\\%_').'%';

        return Referencia::query()
            ->where(fn ($w) => $w->where('codigo_referencia', 'like', $like)->orWhere('codigo_fabricante', 'like', $like))
            ->pluck('id')
            ->all();
    }

    /** Deja en `etiqueta_origen` el texto legible de cada movimiento. */
    private function resolverEtiquetas(Collection $movimientos): void
    {
        $idsIngreso = $movimientos->where('origen_type', 'ingreso')->pluck('origen_id')->unique()->values();
        $ingresos = $idsIngreso->isEmpty() ? collect()
            : Ingreso::query()->whereIn('id', $idsIngreso)->get(['id', 'tipo', 'numero_documento'])->keyBy('id');

        foreach ($movimientos as $m) {
            $id = $m->origen_id;
            $m->setAttribute('etiqueta_origen', match ($m->origen_type) {
                'ingreso' => $this->etiquetaIngreso($ingresos->get($id), $id),
                'venta' => "Venta #{$id}",
                'ajuste' => "Ajuste #{$id}",
                'reverso' => "Reverso #{$id}",
                IngresoCorreccion::ORIGEN => "Corrección de ingreso #{$id}",
                default => "{$m->origen_type} #{$id}",
            });
        }
    }

    private function etiquetaIngreso(?Ingreso $ingreso, int $id): string
    {
        if ($ingreso === null) {
            return "Ingreso #{$id}";
        }
        $nombre = $ingreso->tipo->value === 'remision' ? 'Remisión' : 'Factura';

        return trim("{$nombre} {$ingreso->numero_documento}");
    }
}
