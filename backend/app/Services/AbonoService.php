<?php

namespace App\Services;

use App\Enums\EstadoVenta;
use App\Enums\FormaPago;
use App\Enums\MedioPago;
use App\Exceptions\ReglaInventarioException;
use App\Models\AbonoAnulacion;
use App\Models\AbonoCliente;
use App\Models\Cliente;
use App\Models\User;
use App\Models\Venta;
use App\Support\RangoFechas;
use Illuminate\Support\Facades\DB;

/**
 * RF-15 (S-30): abonos de clientes. Un abono nunca se edita ni se borra; anularlo es un
 * movimiento nuevo con motivo, usuario y fecha (misma lógica de RF-07, regla 8). Un abono mayor al
 * saldo se permite: queda saldo a favor. Los movimientos de dinero no tocan existencias.
 * S-32: un abono puede ir dirigido a una venta a crédito cerrada del cliente con pendiente, por no
 * más que ese pendiente. Sin venta, es general (FIFO), como antes.
 */
class AbonoService
{
    public function __construct(private readonly CarteraService $cartera) {}

    public function registrar(
        Cliente $cliente,
        string $fecha,
        string|int|float $valor,
        MedioPago $medio,
        ?string $referencia,
        ?string $nota,
        User $usuario,
        ?int $ventaId = null,
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

        if ($ventaId === null) {
            return $this->crear($cliente, null, $fecha, $valor, $medio, $referencia, $nota, $usuario);
        }

        // Dirigido: el bloqueo de la venta serializa dos abonos a la misma venta, para que entre los
        // dos no superen su pendiente.
        return DB::transaction(function () use ($cliente, $ventaId, $fecha, $valor, $medio, $referencia, $nota, $usuario) {
            $venta = Venta::query()->lockForUpdate()->find($ventaId);

            if (! $venta || (int) $venta->cliente_id !== (int) $cliente->id) {
                throw new ReglaInventarioException("La venta #{$ventaId} no es de este cliente.", 422, ['campo' => 'venta_id']);
            }
            if ($venta->forma_pago !== FormaPago::Credito) {
                throw new ReglaInventarioException("La venta #{$ventaId} es de contado: no recibe abonos.", 422, ['campo' => 'venta_id']);
            }
            if ($venta->estado !== EstadoVenta::Cerrada) {
                throw new ReglaInventarioException("La venta #{$ventaId} está abierta: ciérrela antes de abonarle.", 422, ['campo' => 'venta_id']);
            }
            if ($fecha < $venta->cerrada_at->copy()->setTimezone(config('app.zona_local', 'America/Bogota'))->toDateString()) {
                throw new ReglaInventarioException("La fecha del abono no puede ser anterior al cierre de la venta #{$ventaId}.", 422, ['campo' => 'fecha']);
            }

            $pendiente = $this->cartera->pendienteDeVenta($cliente, $venta->id) ?? '0.00';
            if (bccomp($pendiente, '0', 2) <= 0) {
                throw new ReglaInventarioException("La venta #{$ventaId} no tiene saldo pendiente.", 422, ['campo' => 'venta_id']);
            }
            if (bccomp((string) $valor, $pendiente, 2) > 0) {
                $tope = number_format((float) $pendiente, 0, ',', '.');
                throw new ReglaInventarioException(
                    "El abono supera lo pendiente de la venta #{$ventaId} (\${$tope}). Registre hasta \${$tope} a esta venta y el resto como abono general.",
                    422,
                    ['campo' => 'valor', 'pendiente' => $pendiente],
                );
            }

            return $this->crear($cliente, $venta->id, $fecha, $valor, $medio, $referencia, $nota, $usuario);
        });
    }

    private function crear(
        Cliente $cliente,
        ?int $ventaId,
        string $fecha,
        string|int|float $valor,
        MedioPago $medio,
        ?string $referencia,
        ?string $nota,
        User $usuario,
    ): AbonoCliente {
        return AbonoCliente::create([
            'cliente_id' => $cliente->id,
            'venta_id' => $ventaId,
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
