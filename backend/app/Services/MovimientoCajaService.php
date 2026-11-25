<?php

namespace App\Services;

use App\Exceptions\ReglaInventarioException;
use App\Models\CategoriaCaja;
use App\Models\CuentaPago;
use App\Models\MovimientoCaja;
use App\Models\MovimientoCajaAnulacion;
use App\Models\User;
use App\Support\RangoFechas;
use Illuminate\Support\Facades\DB;

/**
 * RF-26 (S-51 a S-53): gastos, gastos fijos, cuotas bancarias, ingresos externos y otros ingresos del
 * mostrador. Inmutables: un error se anula con motivo (movimiento nuevo) y se registra otro.
 *
 * - S-52: vario, fijo, financiero e ingreso_externo solo en una cuenta de tipo efectivo; otro_ingreso
 *   en cualquier cuenta activa.
 * - La fecha es hoy o anterior, no antes del saldo inicial, y solo si ese día no tiene cierre vigente
 *   ni hay un cierre vigente posterior (cambiaría un saldo ya encadenado, S-53).
 */
class MovimientoCajaService
{
    public function __construct(private readonly CajaService $caja) {}

    /** @param  array{fecha?: ?string, categoria_caja_id: int, concepto: string, valor: string|int|float, cuenta_pago_id: int, referencia?: ?string}  $datos */
    public function registrar(array $datos, User $usuario): MovimientoCaja
    {
        $fecha = $datos['fecha'] ?? RangoFechas::hoy();

        $categoria = CategoriaCaja::find($datos['categoria_caja_id']);
        if (! $categoria || ! $categoria->activa) {
            throw new ReglaInventarioException('Elija una categoría activa.', 422, ['campo' => 'categoria_caja_id']);
        }

        $cuenta = CuentaPago::find($datos['cuenta_pago_id']);
        if (! $cuenta || ! $cuenta->activa) {
            throw new ReglaInventarioException('Elija una cuenta activa.', 422, ['campo' => 'cuenta_pago_id']);
        }
        if ($categoria->grupo->soloEfectivo() && ! $cuenta->esEfectivo()) {
            throw new ReglaInventarioException(
                "Un {$this->texto($categoria)} solo se registra en efectivo: los pagos por banco no pasan por la caja del mostrador.",
                422,
                ['campo' => 'cuenta_pago_id'],
            );
        }

        if (! is_numeric($datos['valor']) || bccomp((string) $datos['valor'], '0', 2) <= 0) {
            throw new ReglaInventarioException('El valor debe ser mayor que 0.', 422, ['campo' => 'valor']);
        }

        return DB::transaction(function () use ($datos, $fecha, $categoria, $cuenta, $usuario) {
            $this->caja->bloquearCaja();
            $this->exigirFechaEditable($fecha, 'registrar movimientos');

            return MovimientoCaja::create([
                'fecha' => $fecha,
                'categoria_caja_id' => $categoria->id,
                'grupo' => $categoria->grupo,
                'concepto' => trim($datos['concepto']),
                'valor' => $datos['valor'],
                'cuenta_pago_id' => $cuenta->id,
                'referencia' => self::textoOpcional($datos['referencia'] ?? null),
                'usuario_id' => $usuario->id,
            ]);
        });
    }

    /** S-53: solo mientras el día del movimiento no esté cerrado; una sola vez. */
    public function anular(MovimientoCaja $movimiento, string $motivo, User $usuario): MovimientoCajaAnulacion
    {
        return DB::transaction(function () use ($movimiento, $motivo, $usuario) {
            $this->caja->bloquearCaja();
            $movimiento = MovimientoCaja::query()->lockForUpdate()->findOrFail($movimiento->id);

            if (MovimientoCajaAnulacion::where('movimiento_id', $movimiento->id)->exists()) {
                throw new ReglaInventarioException('Este movimiento ya fue anulado.', 422);
            }
            $this->exigirFechaEditable($movimiento->fecha->toDateString(), 'anular sus movimientos');

            return MovimientoCajaAnulacion::create([
                'movimiento_id' => $movimiento->id,
                'motivo' => trim($motivo),
                'usuario_id' => $usuario->id,
            ]);
        });
    }

    /** Hoy o antes, desde el saldo inicial, sin cierre vigente ese día ni después. */
    public function exigirFechaEditable(string $fecha, string $accion): void
    {
        if ($fecha > RangoFechas::hoy()) {
            throw new ReglaInventarioException('La fecha no puede ser futura.', 422, ['campo' => 'fecha']);
        }

        $inicial = $this->caja->saldoInicial();
        if ($inicial && $fecha < $inicial['fecha']) {
            throw new ReglaInventarioException("La caja empieza el {$inicial['fecha']} (saldo inicial): no se puede {$accion} antes.", 422, ['campo' => 'fecha']);
        }

        if ($this->caja->cierreVigente($fecha)) {
            throw new ReglaInventarioException("El día {$fecha} ya tiene cierre de caja: no se puede {$accion}. Pídale al administrador que lo reabra.", 422, ['campo' => 'fecha']);
        }

        $posterior = $this->caja->ultimoCierreVigente();
        if ($posterior && $posterior->fecha->toDateString() > $fecha) {
            throw new ReglaInventarioException(
                "Ya se cerró la caja del {$posterior->fecha->toDateString()}, posterior al {$fecha}: no se puede {$accion} en un día anterior a un cierre.",
                422,
                ['campo' => 'fecha'],
            );
        }
    }

    private function texto(CategoriaCaja $categoria): string
    {
        return mb_strtolower($categoria->grupo->nombre());
    }

    private static function textoOpcional(?string $texto): ?string
    {
        $texto = trim((string) $texto);

        return $texto === '' ? null : $texto;
    }
}
