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
        return CuentaPago::create($this->aplicarDueno($datos, null))->load('propietario:id,nombre');
    }

    public function actualizar(CuentaPago $cuenta, array $datos): CuentaPago
    {
        return DB::transaction(function () use ($cuenta, $datos) {
            $cuenta = CuentaPago::query()->lockForUpdate()->findOrFail($cuenta->id);
            $cuenta->fill($this->aplicarDueno($datos, $cuenta));

            $dejaEfectivo = $cuenta->getOriginal('tipo') === TipoCuentaPago::Efectivo && $cuenta->getOriginal('activa')
                && (! $cuenta->activa || $cuenta->tipo !== TipoCuentaPago::Efectivo);
            if ($dejaEfectivo && ! CuentaPago::query()->activas()->where('tipo', 'efectivo')->whereKeyNot($cuenta->id)->exists()) {
                throw new ReglaInventarioException('La caja necesita al menos una cuenta de efectivo activa.', 422, ['campo' => 'activa']);
            }

            $cuenta->save();

            return $cuenta->load('propietario:id,nombre');
        });
    }

    /**
     * S-88: el dueño solo cabe en una billetera. Pedirlo en otro tipo es 422. Al dejar de ser
     * billetera se quita el dueño, sin borrar la cuenta ni su historia.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function aplicarDueno(array $datos, ?CuentaPago $cuenta): array
    {
        $tipo = isset($datos['tipo'])
            ? ($datos['tipo'] instanceof TipoCuentaPago ? $datos['tipo'] : TipoCuentaPago::from($datos['tipo']))
            : ($cuenta?->tipo ?? TipoCuentaPago::Banco);
        $pideDueno = array_key_exists('propietario_id', $datos) && $datos['propietario_id'] !== null;

        if ($pideDueno && $tipo !== TipoCuentaPago::Billetera) {
            throw new ReglaInventarioException('El dueño solo aplica a una billetera.', 422, ['campo' => 'propietario_id']);
        }

        if ($tipo !== TipoCuentaPago::Billetera && $cuenta?->propietario_id !== null) {
            $datos['propietario_id'] = null;
        }

        return $datos;
    }
}
