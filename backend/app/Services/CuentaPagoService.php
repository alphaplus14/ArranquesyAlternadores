<?php

namespace App\Services;

use App\Enums\TipoCuentaPago;
use App\Exceptions\ReglaInventarioException;
use App\Models\CuentaPago;
use Illuminate\Support\Facades\DB;

/**
 * RF-24 (S-48): alta y edición de cuentas de pago (solo admin). Nunca se borran. La caja necesita
 * siempre una cuenta de efectivo activa (egresos e ingresos externos solo van en efectivo, S-52).
 */
class CuentaPagoService
{
    public function crear(array $datos): CuentaPago
    {
        return CuentaPago::create($datos);
    }

    public function actualizar(CuentaPago $cuenta, array $datos): CuentaPago
    {
        return DB::transaction(function () use ($cuenta, $datos) {
            $cuenta = CuentaPago::query()->lockForUpdate()->findOrFail($cuenta->id);
            $cuenta->fill($datos);

            $dejaEfectivo = $cuenta->getOriginal('tipo') === TipoCuentaPago::Efectivo && $cuenta->getOriginal('activa')
                && (! $cuenta->activa || $cuenta->tipo !== TipoCuentaPago::Efectivo);
            if ($dejaEfectivo && ! CuentaPago::query()->activas()->where('tipo', 'efectivo')->whereKeyNot($cuenta->id)->exists()) {
                throw new ReglaInventarioException('La caja necesita al menos una cuenta de efectivo activa.', 422, ['campo' => 'activa']);
            }

            $cuenta->save();

            return $cuenta;
        });
    }
}
