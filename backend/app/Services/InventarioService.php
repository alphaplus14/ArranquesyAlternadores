<?php

namespace App\Services;

use App\Enums\EstadoUnidad;
use App\Enums\MetodoCaptura;
use App\Enums\TipoAjuste;
use App\Enums\TipoMovimiento;
use App\Exceptions\ReglaInventarioException;
use App\Models\AjusteInventario;
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

    /** RF-19: unidades nuevas de una línea; lo cubierto por remisiones ligadas ya está en inventario. */
    public static function unidadesNuevas(IngresoItem $item): int
    {
        return max($item->cantidad - (int) $item->cantidad_cubierta, 0);
    }

    /**
     * RF-03 / RF-05: crea una unidad por cada cantidad nueva de cada línea (RF-19: cantidad −
     * cantidad_cubierta), con su código de barras, sube las existencias y registra los
     * movimientos. Todo en bloque para no hacer N consultas.
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
            for ($n = 0; $n < self::unidadesNuevas($item); $n++) {
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
        $porReferencia = $items->groupBy('referencia_id')
            ->map(fn (Collection $lineas) => $lineas->sum(fn (IngresoItem $i) => self::unidadesNuevas($i)))
            ->filter()
            ->sortKeys();

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

        // RF-21: una unidad retirada no se vende; mensaje propio para que mostrador sepa por qué.
        if ($unidad->estaRetirada()) {
            throw new ReglaInventarioException("Unidad retirada del inventario: {$unidad->codigo_barras}.", 422);
        }

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
     * RF-12: $metodo es el de la devolución por código (logística); null cuando el reverso se hace
     * desde la venta (mostrador), sin escanear.
     */
    public function devolver(Unidad $unidad, Reverso $reverso, int $usuarioId, ?MetodoCaptura $metodo = null): void
    {
        $this->exigirTransaccion();

        if ($unidad->estaDisponible()) {
            throw new ReglaInventarioException("La unidad {$unidad->codigo_barras} ya está en inventario.");
        }

        // RF-21: solo vuelve lo vendido; una retirada se devuelve con un reintegro.
        if ($unidad->estaRetirada()) {
            throw new ReglaInventarioException("La unidad {$unidad->codigo_barras} está retirada del inventario.", 422);
        }

        $unidad->estado = EstadoUnidad::Disponible;
        $unidad->save();

        Referencia::whereKey($unidad->referencia_id)->increment('existencia');

        $this->registrarMovimiento(TipoMovimiento::Reverso, $unidad, $usuarioId, $metodo, 'reverso', $reverso->id);
    }

    /**
     * RF-21 (S-27): baja. La unidad disponible sale del inventario (garantía, devolución a
     * proveedor, daño, pérdida…). La unidad debe venir bloqueada con lockForUpdate(); el
     * decremento bloquea la fila de la referencia en la misma transacción.
     */
    public function retirar(Unidad $unidad, AjusteInventario $ajuste, int $usuarioId, MetodoCaptura $metodo): void
    {
        $this->exigirTransaccion();
        $this->exigirEstadoPara(TipoAjuste::Baja, $unidad);

        $unidad->estado = EstadoUnidad::Retirada;
        $unidad->save();

        Referencia::whereKey($unidad->referencia_id)->decrement('existencia');

        $this->registrarMovimiento(TipoMovimiento::Baja, $unidad, $usuarioId, $metodo, 'ajuste', $ajuste->id);
    }

    /**
     * RF-21 (S-27): reintegro. Una unidad retirada por error vuelve a estar disponible. La unidad
     * debe venir bloqueada.
     */
    public function reintegrar(Unidad $unidad, AjusteInventario $ajuste, int $usuarioId, MetodoCaptura $metodo): void
    {
        $this->exigirTransaccion();
        $this->exigirEstadoPara(TipoAjuste::Reintegro, $unidad);

        $unidad->estado = EstadoUnidad::Disponible;
        $unidad->save();

        Referencia::whereKey($unidad->referencia_id)->increment('existencia');

        $this->registrarMovimiento(TipoMovimiento::Reintegro, $unidad, $usuarioId, $metodo, 'ajuste', $ajuste->id);
    }

    /**
     * RF-21: una baja exige una unidad disponible; un reintegro, una retirada. Lo usan también la
     * consulta previa de la pantalla (AjusteService::verificar), para que el mensaje sea el mismo.
     * `codigo` va en el cuerpo del 422 para que la pantalla marque la unidad que falló.
     */
    public function exigirEstadoPara(TipoAjuste $tipo, Unidad $unidad): void
    {
        $codigo = $unidad->codigo_barras;

        $mensaje = match (true) {
            $tipo === TipoAjuste::Baja && $unidad->estado === EstadoUnidad::Vendida => "La unidad {$codigo} está vendida; no se puede retirar.",
            $tipo === TipoAjuste::Baja && $unidad->estado === EstadoUnidad::Retirada => "La unidad {$codigo} ya está retirada del inventario.",
            $tipo === TipoAjuste::Reintegro && $unidad->estado === EstadoUnidad::Disponible => "La unidad {$codigo} ya está en inventario; no hay nada que reintegrar.",
            $tipo === TipoAjuste::Reintegro && $unidad->estado === EstadoUnidad::Vendida => "La unidad {$codigo} está vendida; no se puede reintegrar.",
            default => null,
        };

        if ($mensaje !== null) {
            throw new ReglaInventarioException($mensaje, 422, ['codigo' => $codigo]);
        }
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
