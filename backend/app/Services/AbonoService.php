<?php

namespace App\Services;

use App\Exceptions\ReglaInventarioException;
use App\Models\AbonoAnulacion;
use App\Models\AbonoCliente;
use App\Models\Cliente;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * RF-15: abonos de clientes. Un abono nunca se edita ni se borra: anularlo es un movimiento nuevo
 * con usuario, fecha y motivo (misma lógica de RF-07). No toca existencias.
 * Se puede abonar a un cliente inactivo: desactivarlo solo impide venderle a crédito.
 */
class AbonoService
{
    /** @param  array{fecha?:string, valor:int|string, medio:string, referencia?:?string, nota?:?string}  $datos */
    public function registrar(Cliente $cliente, array $datos, User $usuario): AbonoCliente
    {
        return AbonoCliente::create([
            'cliente_id' => $cliente->id,
            'fecha' => $datos['fecha'] ?? now()->toDateString(),
            'valor' => $datos['valor'],
            'medio' => $datos['medio'],
            'referencia' => $datos['referencia'] ?? null,
            'nota' => $datos['nota'] ?? null,
            'usuario_id' => $usuario->id,
        ]);
    }

    public function anular(AbonoCliente $abono, string $motivo, User $usuario): AbonoCliente
    {
        return DB::transaction(function () use ($abono, $motivo, $usuario) {
            $abono = AbonoCliente::query()->lockForUpdate()->findOrFail($abono->id);

            // UNIQUE(abono_id) en abono_anulaciones es la última barrera contra una doble anulación.
            if (AbonoAnulacion::where('abono_id', $abono->id)->exists()) {
                throw new ReglaInventarioException('El abono ya fue anulado.', 422);
            }

            AbonoAnulacion::create(['abono_id' => $abono->id, 'motivo' => $motivo, 'usuario_id' => $usuario->id]);

            return $abono;
        });
    }
}
