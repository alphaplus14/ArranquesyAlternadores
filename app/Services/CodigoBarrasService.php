<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * RF-03: código de barras del local (Code128): prefijo + consecutivo con ceros, ej. A8000001.
 * El consecutivo vive en una fila de `secuencias` que se bloquea con lockForUpdate(), así dos
 * ingresos simultáneos nunca reciben el mismo número y un rollback no deja huecos.
 */
class CodigoBarrasService
{
    public const SECUENCIA = 'codigo_barras';

    /** @return list<string> */
    public function reservar(int $cantidad): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('La reserva de códigos debe hacerse dentro de una transacción.');
        }

        if ($cantidad < 1) {
            return [];
        }

        DB::table('secuencias')->insertOrIgnore(['nombre' => self::SECUENCIA, 'valor' => 0]);

        $actual = (int) DB::table('secuencias')
            ->where('nombre', self::SECUENCIA)
            ->lockForUpdate()
            ->value('valor');

        DB::table('secuencias')
            ->where('nombre', self::SECUENCIA)
            ->update(['valor' => $actual + $cantidad]);

        return array_map($this->formatear(...), range($actual + 1, $actual + $cantidad));
    }

    public function formatear(int $consecutivo): string
    {
        return config('etiquetas.prefijo').str_pad((string) $consecutivo, config('etiquetas.digitos'), '0', STR_PAD_LEFT);
    }
}
