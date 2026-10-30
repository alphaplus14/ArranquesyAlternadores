<?php

namespace App\Services;

use App\Enums\EstadoUnidad;
use App\Enums\MetodoCaptura;
use App\Enums\TipoMovimiento;
use App\Exceptions\ReglaInventarioException;
use App\Models\Ingreso;
use App\Models\IngresoItem;
use App\Models\MovimientoInventario;
use App\Models\Referencia;
use App\Models\Reverso;
use App\Models\Unidad;
use App\Models\Venta;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Único punto que cambia `referencias.existencia` (RF-02) y el estado de las unidades (RF-03).
 * Siempre se llama dentro de la transacción del servicio que origina el movimiento, para que
 * contador, unidad y movimiento se confirmen o se reviertan juntos.
 *
 * El contador se mueve con UPDATE directo: es un efecto de un movimiento ya registrado en
 * movimientos_inventario, no una edición de datos, así que no pasa por la auditoría (RF-11).
 */
class InventarioService
{
    private const LOTE = 500;

    /**
     * RF-03 / RF-05: crea una unidad por cada cantidad de cada línea, con su código de barras,
     * sube las existencias y registra los movimientos. Todo en bloque para no hacer N consultas.
     *
     * @param  Collection<int, IngresoItem>  $items
     * @param  list<string>  $codigos  uno por unidad, en orden
     */
    public function darEntrada(Ingreso $ingreso, Collection $items, array $codigos, int $usuarioId): void
    {
        $this->exigirTransaccion();

        $ahora = now();
        $filas = [];
        $i = 0;

        foreach ($items as $item) {
            for ($n = 0; $n < $item->cantidad; $n++) {
                $filas[] = [
                    'referencia_id' => $item->referencia_id,
                    'ingreso_item_id' => $item->id,
                    'codigo_barras' => $codigos[$i++],
                    'estado' => EstadoUnidad::Disponible->value,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ];
            }
        }

        foreach (array_chunk($filas, self::LOTE) as $lote) {
            Unidad::insert($lote);
        }

        $creadas = Unidad::query()
            ->whereIn('ingreso_item_id', $items->pluck('id'))
            ->get(['id', 'referencia_id']);

        $movimientos = $creadas->map(fn (Unidad $u) => [
            'tipo' => TipoMovimiento::Ingreso->value,
            'unidad_id' => $u->id,
            'referencia_id' => $u->referencia_id,
            'usuario_id' => $usuarioId,
            'metodo_captura' => null,
            'origen_type' => 'ingreso',
            'origen_id' => $ingreso->id,
            'created_at' => $ahora,
        ])->all();

        foreach (array_chunk($movimientos, self::LOTE) as $lote) {
            MovimientoInventario::insert($lote);
        }

        // Orden fijo por id para que dos ingresos simultáneos bloqueen referencias en el mismo orden.
        $porReferencia = $items->groupBy('referencia_id')->map->sum('cantidad')->sortKeys();

        foreach ($porReferencia as $referenciaId => $cantidad) {
            Referencia::whereKey($referenciaId)->increment('existencia', $cantidad);
        }
    }

    /**
     * RF-06: la unidad sale del inventario. La unidad debe venir bloqueada con lockForUpdate().
     */
    public function darSalida(Unidad $unidad, Venta $venta, int $usuarioId, MetodoCaptura $metodo): void
    {
        $this->exigirTransaccion();

        if (! $unidad->estaDisponible()) {
            throw new ReglaInventarioException("La unidad {$unidad->codigo_barras} no está disponible.");
        }

        $unidad->estado = EstadoUnidad::Vendida;
        $unidad->save();

        Referencia::whereKey($unidad->referencia_id)->decrement('existencia');

        $this->registrarMovimiento(TipoMovimiento::Venta, $unidad, $usuarioId, $metodo, 'venta', $venta->id);
    }

    /**
     * RF-07: la unidad vuelve al inventario por un reverso. La unidad debe venir bloqueada.
     */
    public function devolver(Unidad $unidad, Reverso $reverso, int $usuarioId): void
    {
        $this->exigirTransaccion();

        if ($unidad->estaDisponible()) {
            throw new ReglaInventarioException("La unidad {$unidad->codigo_barras} ya está en inventario.");
        }

        $unidad->estado = EstadoUnidad::Disponible;
        $unidad->save();

        Referencia::whereKey($unidad->referencia_id)->increment('existencia');

        $this->registrarMovimiento(TipoMovimiento::Reverso, $unidad, $usuarioId, null, 'reverso', $reverso->id);
    }

    private function registrarMovimiento(
        TipoMovimiento $tipo,
        Unidad $unidad,
        int $usuarioId,
        ?MetodoCaptura $metodo,
        string $origenType,
        int $origenId,
    ): void {
        MovimientoInventario::create([
            'tipo' => $tipo,
            'unidad_id' => $unidad->id,
            'referencia_id' => $unidad->referencia_id,
            'usuario_id' => $usuarioId,
            'metodo_captura' => $metodo,
            'origen_type' => $origenType,
            'origen_id' => $origenId,
        ]);
    }

    private function exigirTransaccion(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Los movimientos de inventario deben ejecutarse dentro de una transacción.');
        }
    }
}
