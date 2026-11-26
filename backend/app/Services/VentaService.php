<?php

namespace App\Services;

use App\Enums\EstadoVenta;
use App\Enums\FormaPago;
use App\Enums\MetodoCaptura;
use App\Enums\OrigenCodigo;
use App\Enums\TipoDocumentoVenta;
use App\Exceptions\ReglaInventarioException;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\CuentaPago;
use App\Models\Unidad;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Models\VentaPago;
use App\Support\RangoFechas;
use App\Support\TotalesVenta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RF-06: el descargue es la venta de mostrador. Cada escaneo descuenta la unidad de inmediato,
 * no al cerrar, y deja el usuario responsable. El sistema no factura (eso es Contapyme).
 * RF-12: cada ítem registra si el código fue escaneado o digitado.
 * RF-14: de contado o a crédito. El crédito exige un cliente activo; cada ítem guarda el precio
 * y el IVA con que se vendió (S-10, S-15) y, al cerrar, la venta queda con su vencimiento (S-11).
 * S-46: la venta nace con su primer ítem (abrirYEscanear); ningún camino HTTP crea una venta vacía.
 * Las abiertas sin ítems vigentes que queden se descartan solas (descartarVacias), sin borrarse.
 */
class VentaService
{
    public function __construct(
        private readonly InventarioService $inventario,
        private readonly ResolverCodigoService $resolver,
        private readonly CajaService $caja,
    ) {}

    /**
     * Crea la venta SIN ítems. S-46: no se usa desde HTTP (POST /ventas va por abrirYEscanear); queda
     * para abrirYEscanear, las pruebas y el benchmark.
     */
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
     * S-46: abre la venta y hace su primer escaneo en UNA transacción. Antes descarta las ventas
     * vacías de días anteriores del mismo usuario. Si el escaneo falla (código inexistente, sin
     * precio, sin unidades, hay que elegir referencia…) se deshace todo: no queda ninguna venta.
     *
     * @return Collection<int, VentaItem>
     */
    public function abrirYEscanear(
        User $usuario,
        FormaPago $formaPago,
        ?int $clienteId,
        ?string $facturaContapyme,
        string $codigo,
        MetodoCaptura $metodo,
        ?int $referenciaId = null,
        int $cantidad = 1,
    ): Collection {
        return DB::transaction(function () use ($usuario, $formaPago, $clienteId, $facturaContapyme, $codigo, $metodo, $referenciaId, $cantidad) {
            $this->descartarVacias($usuario);
            $venta = $this->abrir($usuario, $formaPago, $clienteId, $facturaContapyme);

            return $this->escanearVarias($venta, $codigo, $metodo, $usuario, $referenciaId, $cantidad);
        });
    }

    /**
     * S-46: ventas abiertas sin ítems vigentes (nunca tuvieron, o se les reversaron todos), del usuario
     * o de todos; de días anteriores (día de Bogotá) salvo $incluirHoy.
     *
     * @return Builder<Venta>
     */
    public function consultaVacias(?User $usuario = null, bool $incluirHoy = false): Builder
    {
        return Venta::query()
            ->where('estado', EstadoVenta::Abierta->value)
            ->when($usuario, fn ($q) => $q->where('usuario_id', $usuario->id))
            ->when(! $incluirHoy, fn ($q) => $q->where('created_at', '<', RangoFechas::inicio(RangoFechas::hoy())))
            ->whereDoesntHave('items', fn ($q) => $q->whereDoesntHave('reversoItem'));
    }

    /**
     * S-46: pasa a `descartada` las ventas abiertas sin ítems vigentes. No borra nada: la venta y sus
     * filas (ítems reversados, reversos) siguen en la base. descartada_por NULL = descarte automático;
     * la auditoría (RF-11) queda con usuario NULL (sistema). Cada venta se bloquea (en orden de id,
     * igual que escanear y cerrar: primero la venta) y se vuelve a comprobar que siga vacía: si un
     * escaneo entró antes, la venta tiene un ítem y no se toca; si entra después, ve la venta
     * descartada y se rechaza. Nunca queda una descartada con ítems vigentes. Devuelve cuántas.
     */
    public function descartarVacias(?User $usuario = null, bool $incluirHoy = false): int
    {
        $ids = $this->consultaVacias($usuario, $incluirHoy)->orderBy('id')->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($ids) {
            $descartadas = 0;
            $ahora = now();

            foreach ($ids as $id) {
                $venta = Venta::query()->lockForUpdate()->find($id);
                $vigentes = VentaItem::query()->where('venta_id', $id)->whereDoesntHave('reversoItem')->exists();
                if (! $venta?->estaAbierta() || $vigentes) {
                    continue;
                }

                $venta->estado = EstadoVenta::Descartada;
                $venta->descartada_at = $ahora;
                $venta->descartada_por = null;
                $venta->save();

                Auditoria::create([
                    'tabla' => $venta->getTable(),
                    'registro_id' => $venta->id,
                    'campo' => 'estado',
                    'valor_anterior' => EstadoVenta::Abierta->value,
                    'valor_nuevo' => EstadoVenta::Descartada->value,
                    'usuario_id' => null,
                    'created_at' => $ahora,
                ]);
                $descartadas++;
            }

            return $descartadas;
        });
    }

    /**
     * RF-06 / S-31 (modifica la regla 3): la venta acepta dos códigos.
     * 1. La etiqueta del local (unidades.codigo_barras): descarga esa unidad. Es el camino rápido y no
     *    cambia: las mismas consultas de siempre.
     * 2. Si no hay unidad con ese código: el código del proveedor o del fabricante (S-25) de una
     *    referencia ACTIVA. El sistema elige la unidad (ResolverCodigoService, compartido con Ajustes). Si el código está en
     *    varias referencias, 422 con tipo "elegir_referencia" y el cliente reenvía con $referenciaId.
     */
    public function escanear(Venta $venta, string $codigoBarras, MetodoCaptura $metodo, User $usuario, ?int $referenciaId = null): VentaItem
    {
        return $this->escanearVarias($venta, $codigoBarras, $metodo, $usuario, $referenciaId, 1)->first();
    }

    /** S-40: máximo de unidades por lectura con el código del proveedor. */
    public const MAX_CANTIDAD = 50;

    /**
     * S-40: la misma lectura con cantidad. Con una etiqueta del local la cantidad es 1 (una unidad
     * física); con el código del proveedor o fabricante se asignan N unidades DISTINTAS (criterio
     * S-31/S-34, FOR UPDATE SKIP LOCKED) y se crean N ítems en UNA transacción: si no alcanzan, 422 y
     * no se crea ninguno. Mismas reglas de precio (> 0) y de bloqueo que el escaneo de una.
     *
     * @return Collection<int, VentaItem>
     */
    public function escanearVarias(Venta $venta, string $codigoBarras, MetodoCaptura $metodo, User $usuario, ?int $referenciaId, int $cantidad): Collection
    {
        $codigo = trim($codigoBarras);

        if ($cantidad < 1 || $cantidad > self::MAX_CANTIDAD) {
            throw new ReglaInventarioException('La cantidad debe estar entre 1 y '.self::MAX_CANTIDAD.'.', 422, ['campo' => 'cantidad']);
        }

        return DB::transaction(function () use ($venta, $codigo, $metodo, $usuario, $referenciaId, $cantidad) {
            $venta = Venta::query()->lockForUpdate()->findOrFail($venta->id);

            if (! $venta->estaAbierta()) {
                throw new ReglaInventarioException($venta->motivoNoAbierta());
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
                if ($cantidad > 1) {
                    throw new ReglaInventarioException("Cada etiqueta es una unidad: {$codigo} se agrega de a una (cantidad 1).", 422, ['campo' => 'cantidad', 'codigo' => $codigo]);
                }
                if ($referenciaId !== null && $referenciaId !== (int) $unidad->referencia_id) {
                    throw new ReglaInventarioException("La referencia elegida no corresponde al código {$codigo}.", 422);
                }

                $origen = OrigenCodigo::Etiqueta;
                $codigoReferencia = $unidad->ref_codigo;
                $precio = $unidad->ref_precio_venta;
                $iva = $unidad->ref_iva_venta;
                unset($unidad->ref_codigo, $unidad->ref_precio_venta, $unidad->ref_iva_venta);
            } else {
                $referencia = $this->resolver->referencia($codigo, $referenciaId, bloquear: true);
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

            $unidades = match (true) {
                $unidad !== null => collect([$unidad]),
                $cantidad === 1 => collect([$this->resolver->unidadDisponible($referencia)]),
                default => $this->resolver->unidadesDisponibles($referencia, $cantidad, [], bloquear: true),
            };

            return $unidades->map(function (Unidad $u) use ($venta, $precio, $iva, $metodo, $origen, $usuario) {
                $item = VentaItem::create([
                    'venta_id' => $venta->id,
                    'unidad_id' => $u->id,
                    'precio_unitario' => $precio,
                    'iva_porcentaje' => $iva,
                    'metodo_captura' => $metodo,
                    'origen_codigo' => $origen,
                    'usuario_id' => $usuario->id,
                ]);

                $this->inventario->darSalida($u, $venta, $usuario->id, $metodo, $origen);

                return $item->setRelation('unidad', $u);
            })->values();
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

            if ($venta->estaDescartada()) {
                throw new ReglaInventarioException($venta->motivoNoAbierta(), 422);
            }

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

    /**
     * S-42: el mismo precio libre de cambiarPrecio, para varios ítems a la vez (un grupo del modal de
     * cierre: misma referencia y precio). Una transacción con la venta bloqueada; cada ítem que cambia
     * deja su fila de auditoría (valor anterior, nuevo, usuario y fecha), igual que de a uno. Solo en
     * crédito abierto, precio > 0, ítems de esta venta y sin reverso. El mostrador solo en sus ventas.
     *
     * @param  list<int>  $itemIds
     */
    public function cambiarPrecioLote(Venta $venta, array $itemIds, string|int|float $precio, User $usuario): Venta
    {
        // S-43: el admin no llega aquí (la ruta es solo del mostrador); cada mostrador, sus ventas.
        if ($venta->usuario_id !== $usuario->id) {
            throw new ReglaInventarioException('Solo quien abrió la venta puede cambiar sus precios.', 403);
        }

        return DB::transaction(function () use ($venta, $itemIds, $precio, $usuario) {
            $venta = Venta::query()->lockForUpdate()->findOrFail($venta->id);

            if ($venta->estaDescartada()) {
                throw new ReglaInventarioException($venta->motivoNoAbierta(), 422);
            }
            if (! $venta->esCredito()) {
                throw new ReglaInventarioException('Solo en una venta a crédito se puede cambiar el precio.', 422);
            }
            if (! $venta->estaAbierta()) {
                throw new ReglaInventarioException('La venta está cerrada: sus precios quedaron fijos.', 422);
            }
            if (! is_numeric($precio) || (float) $precio <= 0) {
                throw new ReglaInventarioException('El precio debe ser mayor que 0.', 422, ['campo' => 'precio_unitario']);
            }

            $ids = array_values(array_unique(array_map('intval', $itemIds)));
            $items = VentaItem::query()->where('venta_id', $venta->id)->whereKey($ids)->with('reversoItem')->orderBy('id')->lockForUpdate()->get();

            if ($ids === [] || $items->count() !== count($ids)) {
                throw new ReglaInventarioException('Algún ítem no pertenece a esta venta: no se cambió ningún precio.', 422);
            }
            if ($items->contains(fn (VentaItem $i) => $i->reversoItem !== null)) {
                throw new ReglaInventarioException('Algún ítem ya fue reversado: no se cambió ningún precio.', 422);
            }

            $ahora = now();
            foreach ($items as $item) {
                $anterior = $item->precio_unitario;
                $item->precio_unitario = $precio;
                if (! $item->isDirty('precio_unitario')) {
                    continue;
                }
                $item->save();

                Auditoria::create([
                    'tabla' => $item->getTable(),
                    'registro_id' => $item->id,
                    'campo' => 'precio_unitario',
                    'valor_anterior' => $anterior,
                    'valor_nuevo' => $item->precio_unitario,
                    'usuario_id' => $usuario->id,
                    'created_at' => $ahora,
                ]);
            }

            return $venta;
        });
    }

    /**
     * S-33: totales de la venta (ítems vigentes, IVA incluido). Una consulta liviana; la usan el cierre
     * y la respuesta del escaneo.
     */
    public function totales(int $ventaId): TotalesVenta
    {
        return TotalesVenta::desglosar(DB::table('venta_items as vi')
            ->leftJoin('reverso_items as ri', 'ri.venta_item_id', '=', 'vi.id')
            ->where('vi.venta_id', $ventaId)
            ->get(['vi.precio_unitario', 'vi.iva_porcentaje', 'ri.id as reverso'])
            ->map(fn ($f) => ['precio' => $f->precio_unitario, 'iva' => $f->iva_porcentaje, 'vigente' => $f->reverso === null]));
    }

    /**
     * RF-14 cambiado (S-73): venta a crédito de solo valor. Registro de cartera SIN ítems: cliente +
     * tipo de documento + valor total con IVA incluido (+ número opcional en factura_contapyme). Nace
     * cerrada, con vence_el = cierre + plazo del cliente (misma regla que cerrar). El inventario no baja
     * (los productos se descargan aparte con una venta de mostrador) y no entra a ninguna cuenta de
     * pago. Como cerrar una venta, exige la caja de hoy abierta (S-53). Si llega el número, no puede
     * haber otro registro vigente (no anulado) del mismo cliente, tipo y número.
     */
    public function registrarCredito(int $clienteId, TipoDocumentoVenta $tipo, string|int|float $valor, ?string $numero, User $usuario): Venta
    {
        $valor = (string) $valor;
        if (! is_numeric($valor) || bccomp($valor, '0', 2) <= 0) {
            throw new ReglaInventarioException('El valor total debe ser mayor que 0.', 422, ['errors' => ['valor_total' => ['El valor total debe ser mayor que 0.']]]);
        }
        if (preg_match('/\.\d{3,}$/', $valor)) {
            throw new ReglaInventarioException('El valor total admite máximo 2 decimales.', 422, ['errors' => ['valor_total' => ['El valor total admite máximo 2 decimales.']]]);
        }
        $numero = trim((string) $numero);
        $numero = $numero === '' ? null : $numero;

        return DB::transaction(function () use ($clienteId, $tipo, $valor, $numero, $usuario) {
            // El bloqueo del cliente serializa dos registros con el mismo número al mismo tiempo.
            $cliente = Cliente::query()->lockForUpdate()->find($clienteId);
            if (! $cliente || ! $cliente->activo) {
                throw new ReglaInventarioException('Elija un cliente activo.', 422, ['errors' => ['cliente_id' => ['Elija un cliente activo.']]]);
            }

            if ($numero !== null && Venta::query()
                ->where('cliente_id', $cliente->id)
                ->where('forma_pago', FormaPago::Credito->value)
                ->where('tipo_documento', $tipo->value)
                ->where('factura_contapyme', $numero)
                ->whereDoesntHave('reversos')
                ->exists()) {
                throw new ReglaInventarioException(
                    "Ya hay una venta a crédito vigente de {$cliente->nombre} con la {$tipo->descripcion($numero)}.",
                    422,
                    ['errors' => ['numero_documento' => ['Ese número ya está registrado para este cliente.']]],
                );
            }

            $this->caja->exigirDiaAbierto('registrar ventas a crédito');

            $venta = new Venta([
                'usuario_id' => $usuario->id,
                'forma_pago' => FormaPago::Credito,
                'cliente_id' => $cliente->id,
                'factura_contapyme' => $numero,
                'tipo_documento' => $tipo,
                'valor_total' => $valor,
            ]);
            $venta->estado = EstadoVenta::Cerrada;
            $venta->cerrada_at = now();
            // RF-14 / S-11: vence = fecha de cierre + plazo del cliente (igual que al cerrar).
            $venta->vence_el = $venta->cerrada_at->copy()->startOfDay()->addDays((int) $cliente->plazo_dias);
            $venta->save();

            // RF-11: auditoría de la creación (campo "*", foto del registro), como en Auditable.
            Auditoria::insert([
                'tabla' => 'ventas',
                'registro_id' => $venta->id,
                'campo' => '*',
                'valor_anterior' => null,
                'valor_nuevo' => json_encode([
                    'forma_pago' => FormaPago::Credito->value,
                    'cliente_id' => $cliente->id,
                    'tipo_documento' => $tipo->value,
                    'factura_contapyme' => $numero,
                    'valor_total' => CajaService::dinero($valor),
                    'estado' => EstadoVenta::Cerrada->value,
                    'vence_el' => $venta->vence_el->toDateString(),
                ], JSON_UNESCAPED_UNICODE),
                'usuario_id' => $usuario->id,
                'created_at' => now(),
            ]);

            return $venta;
        });
    }

    /**
     * RF-24 (S-48): de contado exige `pagos` (cuenta activa, valor > 0, referencia opcional) cuya suma
     * sea EXACTAMENTE el total con IVA incluido (S-33); se guardan en la misma transacción del cierre.
     * A crédito no recibe pagos (el cliente abona en Cartera). S-53: no cierra si la caja de hoy ya
     * está cerrada.
     *
     * @param  list<array{cuenta_pago_id: int|string, valor: int|float|string, referencia?: ?string}>  $pagos
     */
    public function cerrar(Venta $venta, array $pagos = []): Venta
    {
        return DB::transaction(function () use ($venta, $pagos) {
            $venta = Venta::query()->lockForUpdate()->findOrFail($venta->id);

            if (! $venta->estaAbierta()) {
                throw new ReglaInventarioException($venta->motivoNoAbierta('La venta ya está cerrada.'));
            }

            if ($venta->esCredito() && $pagos !== []) {
                throw new ReglaInventarioException('Una venta a crédito no recibe pagos al cerrarla: el cliente abona después en Cartera.', 422, ['campo' => 'pagos']);
            }
            if (! $venta->esCredito()) {
                $this->validarPagos($venta, $pagos);
            }

            $this->caja->exigirDiaAbierto('cerrar ventas');

            $venta->estado = EstadoVenta::Cerrada;
            $venta->cerrada_at = now();

            // RF-14 / S-11: vence = fecha de cierre + plazo del cliente.
            if ($venta->esCredito()) {
                $plazo = (int) Cliente::whereKey($venta->cliente_id)->value('plazo_dias');
                $venta->vence_el = $venta->cerrada_at->copy()->startOfDay()->addDays($plazo);
            }

            $venta->save();

            foreach ($pagos as $p) {
                $referencia = trim((string) ($p['referencia'] ?? ''));
                VentaPago::create([
                    'venta_id' => $venta->id,
                    'cuenta_pago_id' => (int) $p['cuenta_pago_id'],
                    'valor' => CajaService::dinero((string) $p['valor']),
                    'referencia' => $referencia === '' ? null : $referencia,
                ]);
            }

            return $venta;
        });
    }

    /**
     * S-48: mínimo un pago, cuentas activas, valores > 0 y suma igual al total. Una venta sin valor
     * por cobrar (sin ítems vigentes) se cierra sin pagos, como antes.
     */
    private function validarPagos(Venta $venta, array $pagos): void
    {
        $total = $this->totales($venta->id)->total;

        if (bccomp($total, '0', 2) <= 0) {
            if ($pagos !== []) {
                throw new ReglaInventarioException('La venta no tiene valor por cobrar: se cierra sin pagos.', 422, ['campo' => 'pagos']);
            }

            return;
        }
        if ($pagos === []) {
            throw new ReglaInventarioException('Indique la forma de pago: al menos una cuenta con su valor.', 422, ['campo' => 'pagos']);
        }

        $ids = array_map(fn ($p) => (int) ($p['cuenta_pago_id'] ?? 0), $pagos);
        $activas = CuentaPago::query()->activas()->whereKey($ids)->pluck('id')->all();
        $suma = '0.00';
        foreach ($pagos as $i => $p) {
            if (! in_array($ids[$i], $activas, true)) {
                throw new ReglaInventarioException('Alguna cuenta del pago no existe o está inactiva.', 422, ['campo' => "pagos.{$i}.cuenta_pago_id"]);
            }
            $valor = (string) ($p['valor'] ?? '');
            if (! is_numeric($valor) || bccomp($valor, '0', 2) <= 0) {
                throw new ReglaInventarioException('Cada valor del pago debe ser mayor que 0.', 422, ['campo' => "pagos.{$i}.valor"]);
            }
            $suma = bcadd($suma, $valor, 2);
        }

        if (bccomp($suma, $total, 2) !== 0) {
            $diferencia = bcsub($total, $suma, 2);
            $texto = number_format(abs((float) $diferencia), 0, ',', '.');
            throw new ReglaInventarioException(
                bccomp($diferencia, '0', 2) > 0
                    ? "Los pagos suman menos que el total de la venta: faltan \${$texto}."
                    : "Los pagos suman más que el total de la venta: sobran \${$texto}.",
                422,
                ['campo' => 'pagos', 'total' => $total, 'suma' => $suma],
            );
        }
    }
}
