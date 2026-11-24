<?php

namespace App\Services;

use App\Enums\EstadoUnidad;
use App\Enums\EstadoVenta;
use App\Enums\FormaPago;
use App\Enums\MetodoCaptura;
use App\Enums\OrigenCodigo;
use App\Exceptions\ReglaInventarioException;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Referencia;
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

    /**
     * RF-06 / S-31 (modifica la regla 3): la venta acepta dos códigos.
     * 1. La etiqueta del local (unidades.codigo_barras): descarga esa unidad. Es el camino rápido y no
     *    cambia: las mismas consultas de siempre.
     * 2. Si no hay unidad con ese código: el código del proveedor o del fabricante (S-25) de una
     *    referencia ACTIVA. El sistema elige la unidad (ver unidadPorReferencia). Si el código está en
     *    varias referencias, 422 con tipo "elegir_referencia" y el cliente reenvía con $referenciaId.
     */
    public function escanear(Venta $venta, string $codigoBarras, MetodoCaptura $metodo, User $usuario, ?int $referenciaId = null): VentaItem
    {
        $codigo = trim($codigoBarras);

        return DB::transaction(function () use ($venta, $codigo, $metodo, $usuario, $referenciaId) {
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
                ->where('unidades.codigo_barras', $codigo)
                ->select(
                    'unidades.*',
                    'referencias.codigo_referencia as ref_codigo',
                    'referencias.precio_venta as ref_precio_venta',
                    'referencias.iva_venta_porcentaje as ref_iva_venta',
                )
                ->lockForUpdate()
                ->first();

            if ($unidad) {
                if ($referenciaId !== null && $referenciaId !== (int) $unidad->referencia_id) {
                    throw new ReglaInventarioException("La referencia elegida no corresponde al código {$codigo}.", 422);
                }

                $origen = OrigenCodigo::Etiqueta;
                $codigoReferencia = $unidad->ref_codigo;
                $precio = $unidad->ref_precio_venta;
                $iva = $unidad->ref_iva_venta;
                unset($unidad->ref_codigo, $unidad->ref_precio_venta, $unidad->ref_iva_venta);
            } else {
                $referencia = $this->referenciaPorCodigo($codigo, $referenciaId);
                $origen = OrigenCodigo::Referencia;
                $codigoReferencia = $referencia->codigo_referencia;
                $precio = $referencia->precio_venta;
                $iva = $referencia->iva_venta_porcentaje;
            }

            // RF-14 / S-18: ningún precio puede ser 0. Se rechaza antes de crear el ítem y de
            // descontar la unidad.
            if ((float) $precio <= 0) {
                throw new ReglaInventarioException(
                    "La referencia {$codigoReferencia} no tiene precio de venta; pídale al administrador que se lo asigne.",
                    422,
                );
            }

            $unidad ??= $this->unidadPorReferencia($referencia);

            $item = VentaItem::create([
                'venta_id' => $venta->id,
                'unidad_id' => $unidad->id,
                'precio_unitario' => $precio,
                'iva_porcentaje' => $iva,
                'metodo_captura' => $metodo,
                'origen_codigo' => $origen,
                'usuario_id' => $usuario->id,
            ]);

            $this->inventario->darSalida($unidad, $venta, $usuario->id, $metodo, $origen);

            return $item->setRelation('unidad', $unidad);
        });
    }

    /**
     * S-31: la referencia activa cuyo código del proveedor o del fabricante es $codigo. Con
     * $referenciaId, esa (si coincide con el código). Sin él: 0 → 404 como hoy; 2 o más → 422 para
     * elegir (máx. 8), no se adivina.
     */
    private function referenciaPorCodigo(string $codigo, ?int $referenciaId): Referencia
    {
        $columnas = ['referencias.id', 'codigo_referencia', 'descripcion', 'proveedor_id', 'calidad', 'existencia', 'precio_venta', 'iva_venta_porcentaje'];
        $consulta = Referencia::query()->activas()->porCodigoProveedor($codigo)->with('proveedor:id,nombre');

        if ($referenciaId !== null) {
            return $this->bloquear($consulta->whereKey($referenciaId)->first($columnas)
                ?? throw new ReglaInventarioException("La referencia elegida no corresponde al código {$codigo}.", 422));
        }

        $coinciden = $consulta->orderBy('referencias.id')->limit(9)->get($columnas);

        if ($coinciden->isEmpty()) {
            throw new ReglaInventarioException("No existe una unidad con el código {$codigo}.", 404);
        }

        if ($coinciden->count() > 1) {
            throw new ReglaInventarioException(
                "El código {$codigo} está en varias referencias: elija cuál se vende.",
                422,
                [
                    'tipo' => 'elegir_referencia',
                    'codigo' => $codigo,
                    'referencias' => $coinciden->take(8)->map(fn (Referencia $r) => [
                        'id' => $r->id,
                        'codigo_referencia' => $r->codigo_referencia,
                        'descripcion' => $r->descripcion,
                        'proveedor' => $r->proveedor?->nombre,
                        'calidad' => $r->calidad,
                        'existencia' => $r->existencia,
                    ])->values()->all(),
                ],
            );
        }

        return $this->bloquear($coinciden->first());
    }

    /**
     * S-31: bloquea la fila de la referencia (por PK) ANTES de elegir la unidad. El decremento de
     * existencia la bloquearía igual al final; tomarla primero deja un solo orden de bloqueo
     * (referencia → unidad) y evita el deadlock entre dos ventas por el mismo código: sin esto, cada
     * una deja gap locks en el índice de unidades que la otra necesita al marcar la unidad vendida.
     * Lee de nuevo precio y existencia ya bloqueados.
     */
    private function bloquear(Referencia $referencia): Referencia
    {
        $bloqueada = Referencia::query()->whereKey($referencia->id)->lockForUpdate()
            ->first(['id', 'codigo_referencia', 'descripcion', 'proveedor_id', 'calidad', 'existencia', 'precio_venta', 'iva_venta_porcentaje']);

        return $bloqueada->setRelation('proveedor', $referencia->proveedor);
    }

    /**
     * S-31: unidad disponible de la referencia. Primero las que no tienen etiqueta impresa y, entre
     * ellas, la más antigua (menor id); si no hay, la disponible más antigua. Cada consulta lee en
     * orden de índice ((referencia_id, estado, etiqueta_impresa_at) y (referencia_id, estado), ambos
     * con el id al final) con LIMIT 1 y FOR UPDATE SKIP LOCKED: bloquea una sola fila y salta las que
     * otro mostrador tiene tomadas, así dos ventas simultáneas no se esperan ni toman la misma.
     */
    private function unidadPorReferencia(Referencia $referencia): Unidad
    {
        $disponibles = fn () => Unidad::query()
            ->where('referencia_id', $referencia->id)
            ->where('estado', EstadoUnidad::Disponible->value)
            ->orderBy('id')
            ->lock('for update skip locked');

        // FORCE INDEX: con el índice (referencia_id, estado) MySQL recorrería también las unidades
        // etiquetadas filtrándolas una a una, y en REPEATABLE READ las dejaría bloqueadas.
        $unidad = $disponibles()->forceIndex('unidades_ref_estado_etiqueta_index')->whereNull('etiqueta_impresa_at')->first()
            ?? $disponibles()->first();

        if (! $unidad) {
            throw new ReglaInventarioException(
                "La referencia {$referencia->codigo_referencia} ({$referencia->proveedor?->nombre}) no tiene unidades disponibles.",
                422,
            );
        }

        return $unidad;
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
