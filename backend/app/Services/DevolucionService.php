<?php

namespace App\Services;

use App\Enums\EstadoUnidad;
use App\Enums\MetodoCaptura;
use App\Enums\TipoReverso;
use App\Exceptions\ReglaInventarioException;
use App\Models\Unidad;
use App\Models\User;
use App\Models\VentaItem;

/**
 * RF-07 / S-27: logística (y admin) registra la devolución de un cliente por el código local de la
 * unidad. Es el mismo reverso tipo Devolución de mostrador (ReversoService), así el saldo del
 * cliente sigue siendo neto de reversos (RF-15). Solo aplica a ventas cerradas; en una venta
 * abierta, mostrador anula el ítem.
 */
class DevolucionService
{
    public function __construct(private readonly ReversoService $reversos) {}

    /** Consulta previa: la venta de la unidad, para mostrarla antes de pedir el motivo. */
    public function consultar(string $codigo): VentaItem
    {
        return $this->itemDevolvible(trim($codigo));
    }

    public function registrar(string $codigo, string $motivo, MetodoCaptura $metodo, User $usuario): VentaItem
    {
        $item = $this->itemDevolvible(trim($codigo));

        // reversar() bloquea la venta y el ítem y vuelve a validar (venta abierta, ya reversado).
        $this->reversos->reversar($item->venta, TipoReverso::Devolucion, $motivo, $item->id, $usuario, $metodo);

        return $this->cargar(VentaItem::query()->whereKey($item->id))->firstOrFail();
    }

    private function itemDevolvible(string $codigo): VentaItem
    {
        $unidad = Unidad::query()->where('codigo_barras', $codigo)->first(['id', 'codigo_barras', 'estado']);

        if (! $unidad) {
            throw new ReglaInventarioException("No existe una unidad con el código {$codigo}.", 404);
        }

        if ($unidad->estado === EstadoUnidad::Retirada) {
            throw new ReglaInventarioException("La unidad {$codigo} está retirada del inventario; no está vendida.", 422);
        }

        if ($unidad->estado === EstadoUnidad::Disponible) {
            $yaReversada = VentaItem::query()->where('unidad_id', $unidad->id)->whereHas('reversoItem')->exists();

            throw new ReglaInventarioException($yaReversada
                ? "La unidad {$codigo} ya fue devuelta o anulada: está en inventario."
                : "La unidad {$codigo} no se ha vendido: está en inventario.", 422);
        }

        // Una unidad devuelta puede venderse otra vez: el ítem vigente es el último sin reverso.
        $item = $this->cargar(VentaItem::query()->where('unidad_id', $unidad->id)->whereDoesntHave('reversoItem'))
            ->orderByDesc('id')
            ->first();

        if (! $item) {
            throw new ReglaInventarioException("La unidad {$codigo} ya fue reversada.", 422);
        }

        if ($item->venta->estaAbierta()) {
            throw new ReglaInventarioException(
                "La unidad {$codigo} está en la venta abierta #{$item->venta->id}: anule el ítem en la venta.",
                422,
            );
        }

        return $item;
    }

    private function cargar($consulta)
    {
        return $consulta->with([
            'venta:id,estado,forma_pago,cliente_id,created_at,cerrada_at',
            'venta.cliente:id,nombre,documento',
            'unidad:id,referencia_id,codigo_barras,estado',
            'unidad.referencia:id,codigo_referencia,descripcion,calidad,proveedor_id',
            'unidad.referencia.proveedor:id,nombre',
            'reversoItem.reverso:id,tipo,motivo,usuario_id,created_at',
        ]);
    }
}
