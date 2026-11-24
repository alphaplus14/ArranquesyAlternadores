<?php

namespace App\Services;

use App\Enums\EstadoIngreso;
use App\Enums\FormaPago;
use App\Exceptions\ReglaInventarioException;
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
 */
class PagoService
{
    public function __construct(private readonly CarteraService $cartera) {}

    /**
     * RF-17 (ajuste): con `ingreso_id` el pago se aplica a ese ingreso a crédito del proveedor y
     * no puede superar lo que tiene pendiente hoy; sin él, se aplica FIFO (S-29).
     *
     * @param  array{fecha?:string, valor:int|string, medio:string, referencia?:?string, nota?:?string, ingreso_id?:?int}  $datos
     */
    public function registrar(Proveedor $proveedor, array $datos, User $usuario): PagoProveedor
    {
        return DB::transaction(function () use ($proveedor, $datos, $usuario) {
            $ingresoId = $datos['ingreso_id'] ?? null;

            if ($ingresoId !== null) {
                // Bloquea al proveedor: dos pagos simultáneos no pasan los dos el tope del pendiente.
                Proveedor::query()->lockForUpdate()->findOrFail($proveedor->id);
                $this->validarPagoAIngreso($proveedor, (int) $ingresoId, (string) $datos['valor']);
            }

            return PagoProveedor::create([
                'proveedor_id' => $proveedor->id,
                'ingreso_id' => $ingresoId,
                'fecha' => $datos['fecha'] ?? now()->toDateString(),
                'valor' => $datos['valor'],
                'medio' => $datos['medio'],
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

            PagoAnulacion::create(['pago_id' => $pago->id, 'motivo' => $motivo, 'usuario_id' => $usuario->id]);

            return $pago;
        });
    }
}
