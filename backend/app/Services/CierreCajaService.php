<?php

namespace App\Services;

use App\Enums\EstadoVenta;
use App\Exceptions\ReglaInventarioException;
use App\Models\CierreCaja;
use App\Models\CierreReapertura;
use App\Models\User;
use App\Models\Venta;
use App\Support\RangoFechas;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RF-25 (S-49, S-53, S-54): cierre del día (mostrador) y reapertura (admin). El vigente de una fecha
 * es el cierre sin reapertura; se impone con la transacción y el bloqueo exclusivo de la caja.
 * S-49: el cierre exige la apertura del día y guarda efectivo_esperado = apertura + neto del día.
 */
class CierreCajaService
{
    public function __construct(
        private readonly CajaService $caja,
        private readonly MovimientoCajaService $movimientos,
    ) {}

    public function cerrar(string $fecha, string|int|float $efectivoContado, ?string $observacion, User $usuario): CierreCaja
    {
        if (! is_numeric($efectivoContado) || bccomp((string) $efectivoContado, '0', 2) < 0) {
            throw new ReglaInventarioException('El efectivo contado no puede ser negativo.', 422, ['campo' => 'efectivo_contado']);
        }

        return DB::transaction(function () use ($fecha, $efectivoContado, $observacion, $usuario) {
            $this->caja->bloquearCaja();

            if ($fecha > RangoFechas::hoy()) {
                throw new ReglaInventarioException('No se puede cerrar un día futuro.', 422, ['campo' => 'fecha']);
            }

            if ($vigente = $this->caja->cierreVigente($fecha)) {
                throw new ReglaInventarioException(
                    "El día {$fecha} ya está cerrado (por {$vigente->usuario?->name}, {$vigente->cerrado_at->setTimezone(config('app.zona_local'))->format('d/m/Y h:i a')}).",
                    422,
                    ['cierre_id' => $vigente->id],
                );
            }

            // Mismas reglas de fecha que los movimientos (no antes de un cierre posterior).
            $this->movimientos->exigirFechaEditable($fecha, 'cerrarlo');

            // S-84 (ajusta S-49 y S-53): se exige apertura EFECTIVA (el arrastre del último cierre o un
            // cambio manual), no una registrada. Sin ninguna (primer día sin cierres), no se cierra.
            $apertura = $this->caja->aperturaDelDia($fecha);
            if ($apertura === null) {
                throw new ReglaInventarioException('Registre la apertura de la caja (primer día sin cierres anteriores).', 422, ['campo' => 'apertura']);
            }

            $abiertas = $this->ventasAbiertas($fecha);
            if ($abiertas->isNotEmpty()) {
                $lista = $abiertas->map(fn ($v) => "#{$v->id}")->implode(', ');
                throw new ReglaInventarioException(
                    "Hay ventas abiertas con ítems ({$lista}): ciérrelas o anule sus ítems antes de cerrar el día.",
                    422,
                    ['ventas_abiertas' => $abiertas->pluck('id')->all()],
                );
            }

            $r = $this->caja->resumenDia($fecha);
            $contado = CajaService::dinero((string) $efectivoContado);
            $esperado = bcadd($apertura['valor'], $r['efectivo_neto_dia'], 2);
            $observacion = trim((string) $observacion);

            return CierreCaja::create([
                'fecha' => $fecha,
                'apertura' => $apertura['valor'],
                'efectivo_contado' => $contado,
                'efectivo_esperado' => $esperado,
                'diferencia' => bcsub($contado, $esperado, 2),
                // Columnas del modelo anterior (saldo encadenado), que se conservan para no romper
                // la lectura de los cierres viejos: en los nuevos repiten apertura y esperado.
                'saldo_anterior' => $apertura['valor'],
                'saldo_acumulado' => $esperado,
                'observacion' => $observacion === '' ? null : $observacion,
                'usuario_id' => $usuario->id,
                'cerrado_at' => now(),
            ]);
        });
    }

    /**
     * S-53: solo admin, motivo obligatorio, una vez por cierre. Se reabre del más reciente hacia
     * atrás (los días ya no se encadenan, pero se mantiene el orden para que la caja se corrija como
     * se cerró).
     */
    public function reabrir(CierreCaja $cierre, string $motivo, User $usuario): CierreReapertura
    {
        return DB::transaction(function () use ($cierre, $motivo, $usuario) {
            $this->caja->bloquearCaja();
            $cierre = CierreCaja::query()->findOrFail($cierre->id);

            if (CierreReapertura::where('cierre_id', $cierre->id)->exists()) {
                throw new ReglaInventarioException('Este cierre ya fue reabierto.', 422);
            }

            $ultimo = $this->caja->ultimoCierreVigente();
            if ($ultimo && $ultimo->fecha->toDateString() > $cierre->fecha->toDateString()) {
                throw new ReglaInventarioException(
                    "Reabra primero el cierre del {$ultimo->fecha->toDateString()}: los días se reabren del más reciente hacia atrás.",
                    422,
                );
            }

            return CierreReapertura::create([
                'cierre_id' => $cierre->id,
                'motivo' => trim($motivo),
                'usuario_id' => $usuario->id,
            ]);
        });
    }

    /**
     * Ventas abiertas con ítems vigentes creadas hasta ese día (de cualquier mostrador): su mercancía
     * ya salió y su dinero todavía no está en la caja.
     */
    public function ventasAbiertas(string $fecha): Collection
    {
        return Venta::query()
            ->where('estado', EstadoVenta::Abierta->value)
            ->where('created_at', '<', RangoFechas::finExclusivo($fecha))
            ->whereHas('items', fn ($q) => $q->whereDoesntHave('reversoItem'))
            ->with('usuario:id,name')
            ->withCount(['items as items_count' => fn ($q) => $q->whereDoesntHave('reversoItem')])
            ->orderBy('id')
            ->get(['id', 'usuario_id', 'forma_pago', 'created_at']);
    }
}
