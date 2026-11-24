<?php

namespace App\Services;

use App\Enums\MedioPago;
use App\Exceptions\ReglaInventarioException;
use App\Models\AbonoAnulacion;
use App\Models\AbonoCliente;
use App\Models\Cliente;
use App\Models\User;
use App\Support\RangoFechas;
use Illuminate\Support\Facades\DB;

/**
 * RF-15 (S-30): abonos de clientes. Un abono nunca se edita ni se borra; anularlo es un
 * movimiento nuevo con motivo, usuario y fecha (misma lógica de RF-07, regla 8). Un abono mayor al
 * saldo se permite: queda saldo a favor. Los movimientos de dinero no tocan existencias.
 */
class AbonoService
{
    public function registrar(
        Cliente $cliente,
        string $fecha,
        string|int|float $valor,
        MedioPago $medio,
        ?string $referencia,
        ?string $nota,
        User $usuario,
    ): AbonoCliente {
        if (! $cliente->activo) {
            throw new ReglaInventarioException('El cliente está inactivo: actívelo para registrarle abonos.', 422);
        }

        if (! is_numeric($valor) || bccomp((string) $valor, '0', 2) <= 0) {
            throw new ReglaInventarioException('El valor del abono debe ser mayor que 0.', 422);
        }

        if ($fecha > RangoFechas::hoy()) {
            throw new ReglaInventarioException('La fecha del abono no puede ser futura.', 422);
        }

        return AbonoCliente::create([
            'cliente_id' => $cliente->id,
            'fecha' => $fecha,
            'valor' => $valor,
            'medio' => $medio,
            'referencia' => self::textoOpcional($referencia),
            'nota' => self::textoOpcional($nota),
            'usuario_id' => $usuario->id,
        ]);
    }

    /** Solo admin (ruta). No se anula dos veces: 422 (y UNIQUE(abono_id) como última barrera). */
    public function anular(AbonoCliente $abono, string $motivo, User $usuario): AbonoAnulacion
    {
        return DB::transaction(function () use ($abono, $motivo, $usuario) {
            $abono = AbonoCliente::query()->lockForUpdate()->findOrFail($abono->id);

            if (AbonoAnulacion::where('abono_id', $abono->id)->exists()) {
                throw new ReglaInventarioException('Este abono ya fue anulado.', 422);
            }

            return AbonoAnulacion::create([
                'abono_id' => $abono->id,
                'motivo' => trim($motivo),
                'usuario_id' => $usuario->id,
            ]);
        });
    }

    private static function textoOpcional(?string $texto): ?string
    {
        $texto = trim((string) $texto);

        return $texto === '' ? null : $texto;
    }
}
