<?php

namespace App\Services;

use App\Enums\EstadoUnidad;
use App\Enums\EstadoVenta;
use App\Enums\MetodoCaptura;
use App\Enums\TipoReverso;
use App\Exceptions\ReglaInventarioException;
use App\Models\Unidad;
use App\Models\User;
use App\Models\VentaItem;

/**
 * RF-07 / S-27: logística (y admin) registra la devolución de un cliente. Es el mismo reverso tipo
 * Devolución de mostrador (ReversoService), así el saldo del cliente sigue siendo neto de reversos
 * (RF-15). Solo aplica a ventas cerradas; en una venta abierta, mostrador anula el ítem.
 *
 * S-34: con la etiqueta del local se encuentra la venta de esa unidad, como siempre. Con el código
 * del proveedor o del fabricante NO se elige la venta automáticamente (cambia el saldo de un cliente
 * concreto, regla 14): se listan las ventas cerradas candidatas y quien registra elige el ítem.
 */
class DevolucionService
{
    /** Máximo de ventas candidatas por código de proveedor. */
    public const MAX_CANDIDATAS = 20;

    public function __construct(
        private readonly ReversoService $reversos,
        private readonly ResolverCodigoService $resolver,
    ) {}

    /**
     * Consulta previa. Con una etiqueta: el ítem vendido de esa unidad (VentaItem). Con un código de
     * proveedor o fabricante: ['tipo' => 'elegir_venta', ...] con las ventas candidatas.
     *
     * @return VentaItem|array<string, mixed>
     */
    public function consultar(string $codigo, ?int $ventaId = null): VentaItem|array
    {
        $codigo = trim($codigo);
        $unidad = Unidad::query()->where('codigo_barras', $codigo)->first(['id', 'codigo_barras', 'estado']);

        return $unidad ? $this->itemDeUnidad($unidad) : $this->candidatas($codigo, $ventaId);
    }

    /** Con $ventaItemId (elegido de la lista de candidatas); sin él, el código debe ser una etiqueta. */
    public function registrar(string $codigo, string $motivo, MetodoCaptura $metodo, User $usuario, ?int $ventaItemId = null): VentaItem
    {
        $codigo = trim($codigo);
        $item = $ventaItemId !== null ? $this->itemElegido($codigo, $ventaItemId) : $this->itemPorEtiqueta($codigo);

        // reversar() bloquea la venta y el ítem y vuelve a validar (venta abierta, ya reversado).
        $this->reversos->reversar($item->venta, TipoReverso::Devolucion, $motivo, $item->id, $usuario, $metodo);

        return $this->cargar(VentaItem::query()->whereKey($item->id))->firstOrFail();
    }

    private function itemPorEtiqueta(string $codigo): VentaItem
    {
        $unidad = Unidad::query()->where('codigo_barras', $codigo)->first(['id', 'codigo_barras', 'estado']);

        if ($unidad) {
            return $this->itemDeUnidad($unidad);
        }

        // Es un código de proveedor: hay que elegir la venta (nunca se adivina).
        $this->resolver->coincidencias($codigo, 1)->isNotEmpty()
            ? throw new ReglaInventarioException("El código {$codigo} es de una referencia: elija la venta que devuelve.", 422)
            : throw $this->noExiste($codigo);
    }

    /** S-34: el ítem elegido de la lista; debe ser de la unidad o de una referencia con ese código. */
    private function itemElegido(string $codigo, int $ventaItemId): VentaItem
    {
        $item = $this->cargar(VentaItem::query()->whereKey($ventaItemId))->first()
            ?? throw new ReglaInventarioException("No existe el ítem de venta #{$ventaItemId}.", 404);

        $delCodigo = $item->unidad->codigo_barras === $codigo
            || $this->resolver->coincidencias($codigo, ResolverCodigoService::MAX_OPCIONES * 4)->contains(fn ($r) => $r->id === $item->unidad->referencia_id);

        if (! $delCodigo) {
            throw new ReglaInventarioException("El ítem elegido no corresponde al código {$codigo}.", 422);
        }

        $unidad = $item->unidad->codigo_barras;

        if ($item->reversoItem) {
            throw new ReglaInventarioException("La unidad {$unidad} de la venta #{$item->venta_id} ya fue devuelta o anulada.", 422);
        }

        if ($item->venta->estaAbierta()) {
            throw new ReglaInventarioException("La unidad {$unidad} está en la venta abierta #{$item->venta_id}: anule el ítem en la venta.", 422);
        }

        return $item;
    }

    private function itemDeUnidad(Unidad $unidad): VentaItem
    {
        $codigo = $unidad->codigo_barras;

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

    /**
     * S-34: ítems vendidos y sin reverso de las referencias con ese código, en ventas CERRADAS, del
     * cierre más reciente al más antiguo, hasta 20. Una sola consulta con JOIN (sin N+1); usa el
     * índice (referencia_id, estado) de unidades: un ítem vigente tiene su unidad vendida.
     *
     * @return array<string, mixed>
     */
    private function candidatas(string $codigo, ?int $ventaId): array
    {
        $referencias = $this->resolver->coincidencias($codigo, 50);

        if ($referencias->isEmpty()) {
            throw $this->noExiste($codigo);
        }

        $filas = VentaItem::query()
            ->join('unidades as u', 'u.id', '=', 'venta_items.unidad_id')
            ->join('ventas as v', 'v.id', '=', 'venta_items.venta_id')
            ->leftJoin('reverso_items as ri', 'ri.venta_item_id', '=', 'venta_items.id')
            ->leftJoin('clientes as c', 'c.id', '=', 'v.cliente_id')
            ->whereIn('u.referencia_id', $referencias->pluck('id'))
            ->where('u.estado', EstadoUnidad::Vendida->value)
            ->where('v.estado', EstadoVenta::Cerrada->value)
            ->whereNull('ri.id')
            ->when($ventaId, fn ($q, $id) => $q->where('v.id', $id))
            ->orderByDesc('v.cerrada_at')
            ->orderByDesc('venta_items.id')
            ->limit(self::MAX_CANDIDATAS)
            ->get([
                'venta_items.id as venta_item_id', 'venta_items.precio_unitario', 'v.id as venta_id', 'v.cerrada_at', 'v.forma_pago',
                'c.id as cliente_id', 'c.nombre as cliente_nombre', 'c.documento as cliente_documento', 'u.codigo_barras', 'u.referencia_id',
            ]);

        if ($filas->isEmpty() && $ventaId === null) {
            $nombres = $referencias->pluck('codigo_referencia')->unique()->implode(', ');
            throw new ReglaInventarioException("No hay ventas cerradas sin devolver de la referencia {$nombres}.", 422);
        }

        $porId = $referencias->keyBy('id');

        return [
            'tipo' => 'elegir_venta',
            'codigo' => $codigo,
            'ventas' => $filas->map(fn ($f) => [
                'venta_item_id' => $f->venta_item_id,
                'venta_id' => $f->venta_id,
                'cerrada_at' => $f->cerrada_at,
                'forma_pago' => $f->forma_pago,
                'cliente' => $f->cliente_id ? ['id' => $f->cliente_id, 'nombre' => $f->cliente_nombre, 'documento' => $f->cliente_documento] : null,
                'precio_unitario' => $f->precio_unitario,
                'codigo_barras' => $f->codigo_barras,
                'referencia' => [
                    'id' => $f->referencia_id,
                    'codigo_referencia' => $porId[$f->referencia_id]->codigo_referencia,
                    'descripcion' => $porId[$f->referencia_id]->descripcion,
                    'proveedor' => $porId[$f->referencia_id]->proveedor?->nombre,
                ],
            ])->values()->all(),
        ];
    }

    private function noExiste(string $codigo): ReglaInventarioException
    {
        return new ReglaInventarioException("No existe una unidad con el código {$codigo}.", 404);
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
