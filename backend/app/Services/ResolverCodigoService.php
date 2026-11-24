<?php

namespace App\Services;

use App\Enums\EstadoUnidad;
use App\Exceptions\ReglaInventarioException;
use App\Models\Referencia;
use App\Models\Unidad;
use Illuminate\Support\Collection;

/**
 * S-31 / S-34: resolución de un código escaneado que NO es la etiqueta del local (unidades.codigo_barras)
 * sino el código del proveedor (codigo_referencia) o del fabricante (codigo_fabricante, S-25) de una
 * referencia ACTIVA, y elección de la unidad disponible. Lo usan Venta, Salidas y Devoluciones; cada
 * uno busca primero la etiqueta por su cuenta (Venta con su JOIN de siempre, sin consultas de más).
 *
 * Búsqueda exacta, sin mayúsculas ni espacios en los extremos, con los índices simples de
 * codigo_referencia y codigo_fabricante (index_merge). Nunca se adivina entre referencias.
 */
class ResolverCodigoService
{
    private const COLUMNAS = ['referencias.id', 'codigo_referencia', 'descripcion', 'proveedor_id', 'calidad', 'existencia', 'precio_venta', 'iva_venta_porcentaje'];

    /** Máximo de referencias que se ofrecen para elegir. */
    public const MAX_OPCIONES = 8;

    /**
     * Referencias activas con ese código, con su proveedor, por id (hasta $limite).
     *
     * @return Collection<int, Referencia>
     */
    public function coincidencias(string $codigo, int $limite = self::MAX_OPCIONES + 1): Collection
    {
        return Referencia::query()->activas()->porCodigoProveedor($codigo)
            ->with('proveedor:id,nombre')
            ->orderBy('referencias.id')
            ->limit($limite)
            ->get(self::COLUMNAS);
    }

    /**
     * La referencia activa con ese código. Con $referenciaId, esa (si coincide con el código; si no,
     * 422). Sin él: ninguna → $noExiste (404 "No existe una unidad con el código X" por defecto);
     * dos o más → 422 tipo 'elegir_referencia' con hasta 8 opciones.
     *
     * $bloquear: bloquea la fila de la referencia (por PK) antes de elegir la unidad. El decremento
     * de existencia la bloquearía igual al final; tomarla primero deja un solo orden de bloqueo
     * (referencia → unidad) y evita el deadlock entre dos lecturas simultáneas del mismo código.
     *
     * @param  string  $accion  verbo para el mensaje de elegir ("se vende", "se retira")
     * @param  (callable(): ReglaInventarioException)|null  $noExiste
     */
    public function referencia(string $codigo, ?int $referenciaId, bool $bloquear, string $accion = 'se vende', ?callable $noExiste = null): Referencia
    {
        $codigo = trim($codigo);

        if ($referenciaId !== null) {
            $elegida = Referencia::query()->activas()->porCodigoProveedor($codigo)->whereKey($referenciaId)
                ->with('proveedor:id,nombre')->first(self::COLUMNAS)
                ?? throw new ReglaInventarioException("La referencia elegida no corresponde al código {$codigo}.", 422);

            return $bloquear ? $this->bloquear($elegida) : $elegida;
        }

        $coinciden = $this->coincidencias($codigo);

        if ($coinciden->isEmpty()) {
            throw $noExiste ? $noExiste() : new ReglaInventarioException("No existe una unidad con el código {$codigo}.", 404);
        }

        if ($coinciden->count() > 1) {
            throw new ReglaInventarioException(
                "El código {$codigo} está en varias referencias: elija cuál {$accion}.",
                422,
                [
                    'tipo' => 'elegir_referencia',
                    'codigo' => $codigo,
                    'referencias' => $coinciden->take(self::MAX_OPCIONES)->map(fn (Referencia $r) => [
                        'id' => $r->id,
                        'codigo_referencia' => $r->codigo_referencia,
                        'descripcion' => $r->descripcion,
                        'proveedor' => $r->proveedor?->nombre,
                        'calidad' => $r->calidad,
                        'existencia' => $r->existencia,
                    ])->values()->all(),
                ],
            );
        }

        return $bloquear ? $this->bloquear($coinciden->first()) : $coinciden->first();
    }

    /**
     * S-31: unidad disponible de la referencia. Primero las que no tienen etiqueta impresa y, entre
     * ellas, la más antigua (menor id); si no hay, la disponible más antigua. $excluir: ids ya
     * tomados (p. ej. en el mismo lote de salida, S-34).
     *
     * Con $bloquear (dentro de una transacción): FOR UPDATE SKIP LOCKED con LIMIT 1 en orden de
     * índice ((referencia_id, estado, etiqueta_impresa_at) y (referencia_id, estado), ambos con el id
     * al final): bloquea una sola fila y salta las que otro usuario tiene tomadas. Sin $bloquear es
     * solo una consulta previa (la pantalla); la regla se revalida al registrar.
     *
     * @param  list<int>  $excluir
     */
    public function unidadDisponible(Referencia $referencia, array $excluir = [], bool $bloquear = true): Unidad
    {
        $disponibles = fn () => Unidad::query()
            ->where('referencia_id', $referencia->id)
            ->where('estado', EstadoUnidad::Disponible->value)
            ->when($excluir !== [], fn ($q) => $q->whereNotIn('id', $excluir))
            ->orderBy('id')
            ->when($bloquear, fn ($q) => $q->lock('for update skip locked'));

        // FORCE INDEX: con el índice (referencia_id, estado) MySQL recorrería también las unidades
        // etiquetadas filtrándolas una a una, y en REPEATABLE READ las dejaría bloqueadas.
        $unidad = $disponibles()->forceIndex('unidades_ref_estado_etiqueta_index')->whereNull('etiqueta_impresa_at')->first()
            ?? $disponibles()->first();

        return $unidad ?? throw $this->sinUnidades($referencia);
    }

    public function sinUnidades(Referencia $referencia): ReglaInventarioException
    {
        return new ReglaInventarioException(
            "La referencia {$referencia->codigo_referencia} ({$referencia->proveedor?->nombre}) no tiene unidades disponibles.",
            422,
        );
    }

    /** Lee de nuevo la referencia con la fila bloqueada (precio y existencia frescos). */
    private function bloquear(Referencia $referencia): Referencia
    {
        $bloqueada = Referencia::query()->whereKey($referencia->id)->lockForUpdate()->first(self::COLUMNAS);

        return $bloqueada->setRelation('proveedor', $referencia->proveedor);
    }
}
