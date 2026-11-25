<?php

namespace App\Services;

use App\Exceptions\ReglaInventarioException;
use App\Models\CuentaPago;
use App\Models\PagoAnulacion;
use App\Models\PagoProveedor;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * RF-17: pagos a proveedores, mismo esquema que los abonos (RF-15). Un pago nunca se edita ni se
 * borra: anularlo es un movimiento nuevo con usuario, fecha y motivo. No toca existencias.
 * RF-24 (S-48): el pago sale de una cuenta activa y `medio` se deriva de su tipo. S-53: entra a la
 * caja de hoy (día de registro), que no debe estar cerrada.
 */
class PagoService
{
    public function __construct(private readonly CajaService $caja) {}

    /** @param  array{fecha?:string, valor:int|string, cuenta_pago_id:int, referencia?:?string, nota?:?string}  $datos */
    public function registrar(Proveedor $proveedor, array $datos, User $usuario): PagoProveedor
    {
        $cuenta = CuentaPago::find($datos['cuenta_pago_id'] ?? null);
        if (! $cuenta || ! $cuenta->activa) {
            throw new ReglaInventarioException('Elija una cuenta activa.', 422, ['campo' => 'cuenta_pago_id']);
        }

        return DB::transaction(function () use ($proveedor, $datos, $cuenta, $usuario) {
            $this->caja->exigirDiaAbierto('registrar pagos a proveedores');

            return PagoProveedor::create([
                'proveedor_id' => $proveedor->id,
                'fecha' => $datos['fecha'] ?? now()->toDateString(),
                'valor' => $datos['valor'],
                'medio' => $cuenta->tipo->medio(),
                'cuenta_pago_id' => $cuenta->id,
                'referencia' => $datos['referencia'] ?? null,
                'nota' => $datos['nota'] ?? null,
                'usuario_id' => $usuario->id,
            ]);
        });
    }

    public function anular(PagoProveedor $pago, string $motivo, User $usuario): PagoProveedor
    {
        return DB::transaction(function () use ($pago, $motivo, $usuario) {
            $pago = PagoProveedor::query()->lockForUpdate()->findOrFail($pago->id);

            // UNIQUE(pago_id) en pago_anulaciones es la última barrera contra una doble anulación.
            if (PagoAnulacion::where('pago_id', $pago->id)->exists()) {
                throw new ReglaInventarioException('El pago ya fue anulado.', 422);
            }
            // S-53: la anulación vuelve a la caja de hoy.
            $this->caja->exigirDiaAbierto('anular pagos a proveedores');

            PagoAnulacion::create(['pago_id' => $pago->id, 'motivo' => $motivo, 'usuario_id' => $usuario->id]);

            return $pago;
        });
    }
}
