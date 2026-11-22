<?php

namespace App\Services;

use App\Exceptions\ReglaInventarioException;
use App\Models\PagoAnulacion;
use App\Models\PagoProveedor;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * RF-17: pagos a proveedores, mismo esquema que los abonos (RF-15). Un pago nunca se edita ni se
 * borra: anularlo es un movimiento nuevo con usuario, fecha y motivo. No toca existencias.
 */
class PagoService
{
    /** @param  array{fecha?:string, valor:int|string, medio:string, referencia?:?string, nota?:?string}  $datos */
    public function registrar(Proveedor $proveedor, array $datos, User $usuario): PagoProveedor
    {
        return PagoProveedor::create([
            'proveedor_id' => $proveedor->id,
            'fecha' => $datos['fecha'] ?? now()->toDateString(),
            'valor' => $datos['valor'],
            'medio' => $datos['medio'],
            'referencia' => $datos['referencia'] ?? null,
            'nota' => $datos['nota'] ?? null,
            'usuario_id' => $usuario->id,
        ]);
    }

    public function anular(PagoProveedor $pago, string $motivo, User $usuario): PagoProveedor
    {
        return DB::transaction(function () use ($pago, $motivo, $usuario) {
            $pago = PagoProveedor::query()->lockForUpdate()->findOrFail($pago->id);

            // UNIQUE(pago_id) en pago_anulaciones es la última barrera contra una doble anulación.
            if (PagoAnulacion::where('pago_id', $pago->id)->exists()) {
                throw new ReglaInventarioException('El pago ya fue anulado.', 422);
            }

            PagoAnulacion::create(['pago_id' => $pago->id, 'motivo' => $motivo, 'usuario_id' => $usuario->id]);

            return $pago;
        });
    }
}
