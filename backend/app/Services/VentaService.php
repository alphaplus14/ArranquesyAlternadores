<?php

namespace App\Services;

use App\Enums\EstadoVenta;
use App\Enums\FormaPago;
use App\Enums\MetodoCaptura;
use App\Exceptions\ReglaInventarioException;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Unidad;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaItem;
use Illuminate\Support\Facades\DB;

/**
 * RF-06: el descargue es la venta de mostrador. Cada escaneo descuenta la unidad de inmediato,
 * no al cerrar, y deja el usuario responsable. El sistema no factura (eso es Contapyme).
 * RF-12: cada ítem registra si el código fue escaneado o digitado.
 * RF-14: de contado o a crédito. El crédito exige un cliente activo; cada ítem guarda el precio
 * y el IVA con que se vendió (S-10, S-15) y, al cerrar, la venta queda con su vencimiento (S-11).
 */
class VentaService
{
    public function __construct(private readonly InventarioService $inventario) {}

    public function abrir(
        User $usuario,
        FormaPago $formaPago = FormaPago::Contado,
        ?int $clienteId = null,
        ?string $facturaContapyme = null,
    ): Venta {
        if ($formaPago === FormaPago::Credito
            && ($clienteId === null || ! Cliente::whereKey($clienteId)->where('activo', true)->exists())) {
            throw new ReglaInventarioException('La venta a crédito exige un cliente activo.', 422);
        }

        return Venta::create([
            'usuario_id' => $usuario->id,
            'forma_pago' => $formaPago,
            'cliente_id' => $clienteId,
            'factura_contapyme' => $facturaContapyme,
        ])->refresh();
    }

    public function escanear(Venta $venta, string $codigoBarras, MetodoCaptura $metodo, User $usuario): VentaItem
    {
        return DB::transaction(function () use ($venta, $codigoBarras, $metodo, $usuario) {
            $venta = Venta::query()->lockForUpdate()->findOrFail($venta->id);

            if (! $venta->estaAbierta()) {
                throw new ReglaInventarioException('La venta está cerrada.');
            }

            // lockForUpdate: si dos mostradores escanean la misma unidad, el segundo espera
            // al primero y luego la encuentra vendida.
            // RF-14: precio e IVA de la referencia llegan en la misma consulta (JOIN), sin un
            // viaje más a la base. La fila de la referencia queda bloqueada desde aquí, pero el
            // decremento de existencia de esta misma transacción la bloquearía de todos modos.
            $unidad = Unidad::query()
                ->join('referencias', 'referencias.id', '=', 'unidades.referencia_id')
                ->where('unidades.codigo_barras', trim($codigoBarras))
                ->select(
                    'unidades.*',
                    'referencias.codigo_referencia as ref_codigo',
                    'referencias.precio_venta as ref_precio_venta',
                    'referencias.iva_venta_porcentaje as ref_iva_venta',
                )
                ->lockForUpdate()
                ->first();

            if (! $unidad) {
                throw new ReglaInventarioException("No existe una unidad con el código {$codigoBarras}.", 404);
            }

            $codigoReferencia = $unidad->ref_codigo;
            $precio = $unidad->ref_precio_venta;
            $iva = $unidad->ref_iva_venta;
            unset($unidad->ref_codigo, $unidad->ref_precio_venta, $unidad->ref_iva_venta);

            // RF-14 / S-18: ningún precio puede ser 0. Se rechaza antes de crear el ítem y de
            // descontar la unidad, con el precio que ya trajo el JOIN (sin otra consulta).
            if ((float) $precio <= 0) {
                throw new ReglaInventarioException(
                    "La referencia {$codigoReferencia} no tiene precio de venta; pídale al administrador que se lo asigne.",
                    422,
                );
            }

            $item = VentaItem::create([
                'venta_id' => $venta->id,
                'unidad_id' => $unidad->id,
                'precio_unitario' => $precio,
                'iva_porcentaje' => $iva,
                'metodo_captura' => $metodo,
                'usuario_id' => $usuario->id,
            ]);

            $this->inventario->darSalida($unidad, $venta, $usuario->id, $metodo);

            return $item->setRelation('unidad', $unidad);
        });
    }

    /**
     * RF-14 / S-18: en una venta a crédito abierta el precio de un ítem vigente puede cambiar a
     * cualquier valor mayor que 0, sin piso. Cada cambio queda en la auditoría (RF-11) en la misma
     * transacción. No toca el inventario. Al cerrar la venta los precios quedan fijos.
     */
    public function cambiarPrecio(Venta $venta, VentaItem $item, string|int|float $precio, User $usuario): Venta
    {
        return DB::transaction(function () use ($venta, $item, $precio, $usuario) {
            // El lock de la venta serializa este cambio con el cierre y con los reversos.
            $venta = Venta::query()->lockForUpdate()->findOrFail($venta->id);

            if (! $venta->esCredito()) {
                throw new ReglaInventarioException('Solo en una venta a crédito se puede cambiar el precio.', 422);
            }

            if (! $venta->estaAbierta()) {
                throw new ReglaInventarioException('La venta está cerrada: sus precios quedaron fijos.', 422);
            }

            $item = VentaItem::query()->where('venta_id', $venta->id)->with('reversoItem')->findOrFail($item->id);

            if ($item->reversoItem) {
                throw new ReglaInventarioException('El ítem fue reversado: ya no tiene precio que cambiar.', 422);
            }

            if (! is_numeric($precio) || (float) $precio <= 0) {
                throw new ReglaInventarioException('El precio debe ser mayor que 0.', 422);
            }

            $anterior = $item->precio_unitario;
            $item->precio_unitario = $precio;

            if (! $item->isDirty('precio_unitario')) {
                return $venta;
            }

            $item->save();

            Auditoria::create([
                'tabla' => $item->getTable(),
                'registro_id' => $item->id,
                'campo' => 'precio_unitario',
                'valor_anterior' => $anterior,
                'valor_nuevo' => $item->precio_unitario,
                'usuario_id' => $usuario->id,
                'created_at' => now(),
            ]);

            return $venta;
        });
    }

    public function cerrar(Venta $venta): Venta
    {
        return DB::transaction(function () use ($venta) {
            $venta = Venta::query()->lockForUpdate()->findOrFail($venta->id);

            if (! $venta->estaAbierta()) {
                throw new ReglaInventarioException('La venta ya está cerrada.');
            }

            $venta->estado = EstadoVenta::Cerrada;
            $venta->cerrada_at = now();

            // RF-14 / S-11: vence = fecha de cierre + plazo del cliente.
            if ($venta->esCredito()) {
                $plazo = (int) Cliente::whereKey($venta->cliente_id)->value('plazo_dias');
                $venta->vence_el = $venta->cerrada_at->copy()->startOfDay()->addDays($plazo);
            }

            $venta->save();

            return $venta;
        });
    }
}
