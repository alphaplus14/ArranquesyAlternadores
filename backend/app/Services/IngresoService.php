<?php

namespace App\Services;

use App\Enums\EstadoIngreso;
use App\Enums\TipoIngreso;
use App\Exceptions\ReglaInventarioException;
use App\Models\Ingreso;
use App\Models\IngresoItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * RF-05: ingreso por remisión, ítem por ítem, al mismo inventario.
 * RF-04: la factura de compra se ingresa a mano, igual que la remisión (sin QR ni XML).
 * RF-09 / RF-10: descuento de la factura y líneas sin IVA.
 * RF-03: al confirmar se crean las unidades con su código de barras.
 */
class IngresoService
{
    public function __construct(
        private readonly InventarioService $inventario,
        private readonly CodigoBarrasService $codigos,
    ) {}

    /** @param array{proveedor_id:int, numero_documento:string, fecha:string, descuento_total?:float} $datos */
    public function crearBorrador(array $datos, User $usuario): Ingreso
    {
        return Ingreso::create([
            ...$datos,
            'tipo' => TipoIngreso::Remision,
            'usuario_id' => $usuario->id,
        ])->refresh();
    }

    public function actualizarEncabezado(Ingreso $ingreso, array $datos): Ingreso
    {
        return $this->enBorrador($ingreso, function (Ingreso $bloqueado) use ($datos) {
            $bloqueado->update($datos);

            return $bloqueado;
        });
    }

    public function agregarItem(Ingreso $ingreso, array $datos): IngresoItem
    {
        return $this->enBorrador($ingreso, fn (Ingreso $bloqueado) => $bloqueado->items()->create($datos));
    }

    public function actualizarItem(Ingreso $ingreso, IngresoItem $item, array $datos): IngresoItem
    {
        return $this->enBorrador($ingreso, function () use ($item, $datos) {
            $item->update($datos);

            return $item;
        });
    }

    /** Solo en borrador: todavía no existen unidades ni movimientos de esta línea. */
    public function quitarItem(Ingreso $ingreso, IngresoItem $item): void
    {
        $this->enBorrador($ingreso, fn () => $item->delete());
    }

    /** RF-05: confirma el ingreso, crea las unidades y suma existencia. Queda quién confirmó. */
    public function confirmar(Ingreso $ingreso, User $usuario): Ingreso
    {
        return DB::transaction(function () use ($ingreso, $usuario) {
            $ingreso = Ingreso::query()->lockForUpdate()->findOrFail($ingreso->id);
            $this->exigirBorrador($ingreso);

            $items = $ingreso->items()->orderBy('id')->get();

            if ($items->isEmpty()) {
                throw new ReglaInventarioException('El ingreso no tiene ítems.', 422);
            }

            $codigos = $this->codigos->reservar($items->sum('cantidad'));
            $this->inventario->darEntrada($ingreso, $items, $codigos, $usuario->id);

            $ingreso->estado = EstadoIngreso::Confirmado;
            $ingreso->confirmado_at = now();
            $ingreso->confirmado_por = $usuario->id;
            $ingreso->save();

            return $ingreso;
        });
    }

    /**
     * Ediciones del borrador con la fila del ingreso bloqueada: así no se cuela un ítem mientras
     * otro usuario lo confirma (quedaría un ítem sin unidades).
     *
     * @template T
     *
     * @param  callable(Ingreso): T  $cambio
     * @return T
     */
    private function enBorrador(Ingreso $ingreso, callable $cambio): mixed
    {
        return DB::transaction(function () use ($ingreso, $cambio) {
            $bloqueado = Ingreso::query()->lockForUpdate()->findOrFail($ingreso->id);
            $this->exigirBorrador($bloqueado);

            return $cambio($bloqueado);
        });
    }

    private function exigirBorrador(Ingreso $ingreso): void
    {
        if (! $ingreso->esBorrador()) {
            throw new ReglaInventarioException('El ingreso ya está confirmado y no se puede modificar.');
        }
    }
}
