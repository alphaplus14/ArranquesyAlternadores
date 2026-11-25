<?php

namespace App\Services;

use App\Enums\EstadoIngreso;
use App\Enums\FormaPago;
use App\Exceptions\ReglaInventarioException;
use App\Models\CuentaPago;
use App\Models\Ingreso;
use App\Models\PagoAnulacion;
use App\Models\PagoProveedor;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * RF-17: pagos a proveedores, mismo esquema que los abonos (RF-15). Un pago nunca se edita ni se
 * borra: anularlo es un movimiento nuevo con usuario, fecha y motivo. No toca existencias.
 * RF-17 (ajuste): con `ingreso_id` el pago se aplica a ese ingreso a crédito y no supera su pendiente.
 * RF-24 (S-48): el pago sale de una cuenta activa y `medio` se deriva de su tipo. S-53: entra a la
 * caja de hoy (día de registro), que no debe estar cerrada.
 */
class PagoService
{
    public function __construct(
        private readonly CarteraService $cartera,
        private readonly CajaService $caja,
    ) {}

    /**
     * Sin `ingreso_id` el pago se aplica FIFO (S-29).
     *
     * @param  array{fecha?:string, valor:int|string, cuenta_pago_id:int, referencia?:?string, nota?:?string, ingreso_id?:?int}  $datos
     */
    public function registrar(Proveedor $proveedor, array $datos, User $usuario): PagoProveedor
    {
        $cuenta = CuentaPago::find($datos['cuenta_pago_id'] ?? null);
        if (! $cuenta || ! $cuenta->activa) {
            throw new ReglaInventarioException('Elija una cuenta activa.', 422, ['campo' => 'cuenta_pago_id']);
        }

        return DB::transaction(function () use ($proveedor, $datos, $cuenta, $usuario) {
            $ingresoId = $datos['ingreso_id'] ?? null;

            if ($ingresoId !== null) {
                // Bloquea al proveedor: dos pagos simultáneos no pasan los dos el tope del pendiente.
                Proveedor::query()->lockForUpdate()->findOrFail($proveedor->id);
                $this->validarPagoAIngreso($proveedor, (int) $ingresoId, (string) $datos['valor']);
            }

            $this->caja->exigirDiaAbierto('registrar pagos a proveedores');

            return PagoProveedor::create([
                'proveedor_id' => $proveedor->id,
                'ingreso_id' => $ingresoId,
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

    private function validarPagoAIngreso(Proveedor $proveedor, int $ingresoId, string $valor): void
    {
        $ingreso = Ingreso::query()->find($ingresoId);

        if (! $ingreso || $ingreso->proveedor_id !== $proveedor->id) {
            throw new ReglaInventarioException('La factura elegida no es de este proveedor.', 422);
        }

        if ($ingreso->estado !== EstadoIngreso::Confirmado || $ingreso->forma_pago !== FormaPago::Credito) {
            throw new ReglaInventarioException('Solo se paga una factura o remisión a crédito ya confirmada.', 422);
        }

        $documento = collect($this->cartera->saldoProveedor($proveedor, Carbon::today())->documentos)
            ->firstWhere('id', $ingresoId);

        if (! $documento) {
            throw new ReglaInventarioException('Ese documento no tiene saldo pendiente: ya está cancelado o lo cubre una factura.', 422);
        }

        if (bccomp($valor, $documento['pendiente'], 2) > 0) {
            $pendiente = number_format((float) $documento['pendiente'], 0, ',', '.');
            throw new ReglaInventarioException("El pago supera lo pendiente de ese documento ({$pendiente}).", 422);
        }
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
