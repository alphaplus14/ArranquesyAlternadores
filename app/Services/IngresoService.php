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
        $this->exigirBorrador($ingreso);
        $ingreso->update($datos);

        return $ingreso;
    }

    public function agregarItem(Ingreso $ingreso, array $datos): IngresoItem
    {
        $this->exigirBorrador($ingreso);

        return $ingreso->items()->create($datos);
    }

    public function actualizarItem(Ingreso $ingreso, IngresoItem $item, array $datos): IngresoItem
    {
        $this->exigirBorrador($ingreso);
        $item->update($datos);

        return $item;
    }

    /** Solo en borrador: todavía no existen unidades ni movimientos de esta línea. */
    public function quitarItem(Ingreso $ingreso, IngresoItem $item): void
    {
        $this->exigirBorrador($ingreso);
        $item->delete();
    }

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
            $ingreso->save();

            return $ingreso;
        });
    }

    private function exigirBorrador(Ingreso $ingreso): void
    {
        if (! $ingreso->esBorrador()) {
            throw new ReglaInventarioException('El ingreso ya está confirmado y no se puede modificar.');
        }
    }
}
