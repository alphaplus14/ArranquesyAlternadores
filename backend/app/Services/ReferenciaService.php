<?php

namespace App\Services;

use App\Exceptions\ReglaInventarioException;
use App\Models\Referencia;
use Illuminate\Support\Facades\DB;

/**
 * S-26: desactivar y reactivar referencias (admin y logística). No se borran: tienen historial
 * de ingresos, ventas y auditoría. No cambia existencias ni unidades; el cambio de `activo` queda
 * en auditoría (trait Auditable).
 */
class ReferenciaService
{
    /** Solo sin unidades disponibles: bloquea la fila para que un ingreso no la cambie a la vez. */
    public function desactivar(Referencia $referencia): Referencia
    {
        return DB::transaction(function () use ($referencia) {
            $bloqueada = Referencia::query()->lockForUpdate()->findOrFail($referencia->id);

            if ($bloqueada->existencia > 0) {
                $n = $bloqueada->existencia;
                throw new ReglaInventarioException(
                    "Tiene {$n} ".($n === 1 ? 'unidad disponible' : 'unidades disponibles').'; no se puede desactivar.',
                    422,
                    ['motivo' => 'con_existencia', 'existencia' => $n],
                );
            }

            if ($bloqueada->activo) {
                $bloqueada->activo = false;
                $bloqueada->save();
            }

            return $bloqueada;
        });
    }

    public function reactivar(Referencia $referencia): Referencia
    {
        if (! $referencia->activo) {
            $referencia->activo = true;
            $referencia->save();
        }

        return $referencia;
    }
}
