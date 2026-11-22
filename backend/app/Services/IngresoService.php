<?php

namespace App\Services;

use App\Enums\EstadoIngreso;
use App\Enums\FormaPago;
use App\Enums\TipoIngreso;
use App\Exceptions\ReglaInventarioException;
use App\Models\Ingreso;
use App\Models\IngresoItem;
use App\Models\Referencia;
use App\Models\User;
use App\Support\TotalesIngreso;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * RF-05: ingreso por remisión, ítem por ítem, al mismo inventario.
 * RF-04: la factura de compra se ingresa a mano, igual que la remisión (sin QR ni XML).
 * RF-09 / RF-10: descuento de la factura y líneas sin IVA.
 * RF-20: los ítems se agregan escaneando el código de referencia del proveedor del ingreso.
 * RF-16: forma de pago y vencimiento digitado; al confirmar se guarda el total (RF-09, ajuste).
 * RF-03: al confirmar se crean las unidades con su código de barras.
 */
class IngresoService
{
    public function __construct(
        private readonly InventarioService $inventario,
        private readonly CodigoBarrasService $codigos,
    ) {}

    /**
     * RF-04 / RF-05: crea el borrador de una remisión o una factura, digitado a mano (sin tipo,
     * remisión). El mismo documento (proveedor + tipo + número) no entra dos veces: si ya hay un
     * borrador se devuelve para reabrirlo (wasRecentlyCreated = false); si está confirmado, se rechaza.
     *
     * @param  array{tipo?:TipoIngreso|string, proveedor_id:int, numero_documento:string, fecha:string, descuento_documento?:float, descuento_documento_porcentaje?:float}  $datos
     */
    public function crearBorrador(array $datos, User $usuario): Ingreso
    {
        $tipo = $datos['tipo'] ?? TipoIngreso::Remision;
        $datos['tipo'] = $tipo instanceof TipoIngreso ? $tipo : TipoIngreso::from($tipo);
        $datos['numero_documento'] = Ingreso::normalizarNumero($datos['numero_documento']);
        $datos = $this->condicionDePago($datos, null);
        $datos = $this->descuentoDocumento($datos);

        if ($existente = $this->buscarDocumento($datos)) {
            return $this->reabrir($existente);
        }

        try {
            return Ingreso::create([...$datos, 'usuario_id' => $usuario->id])->refresh();
        } catch (UniqueConstraintViolationException) {
            // Otro usuario creó el mismo documento al mismo tiempo.
            return $this->reabrir($this->buscarDocumento($datos));
        }
    }

    public function actualizarEncabezado(Ingreso $ingreso, array $datos): Ingreso
    {
        if (isset($datos['numero_documento'])) {
            $datos['numero_documento'] = Ingreso::normalizarNumero($datos['numero_documento']);
        }

        return $this->enBorrador($ingreso, function (Ingreso $bloqueado) use ($datos) {
            $datos = $this->descuentoDocumento($this->condicionDePago($datos, $bloqueado));
            $bloqueado->fill($datos);

            // RF-09: el descuento del documento no puede superar el subtotal neto de las líneas.
            if ($bloqueado->isDirty(['descuento_documento', 'descuento_documento_porcentaje'])) {
                TotalesIngreso::calcular(
                    $bloqueado->items()->get(),
                    $bloqueado->descuento_documento,
                    $bloqueado->descuento_documento_porcentaje,
                )->exigirDescuentoValido();
            }

            try {
                $bloqueado->save();
            } catch (UniqueConstraintViolationException) {
                throw new ReglaInventarioException('Ya hay otro ingreso de ese proveedor con el mismo tipo y número de documento.', 422);
            }

            return $bloqueado;
        });
    }

    /**
     * RF-20 (S-19, S-25): el código leído es el código de barras o la referencia del proveedor del ingreso; se busca
     * solo entre sus referencias, con igualdad exacta (sin prefijos). Si ya hay una línea de esa
     * referencia, suma 1; si no, la crea con cantidad 1 y el último costo confirmado de la
     * referencia (0 si no hay, y se pide antes de confirmar). wasRecentlyCreated dice cuál pasó.
     * Si la referencia no existe responde 404 con motivo referencia_inexistente y el código leído.
     */
    public function escanear(Ingreso $ingreso, string $codigo, ?int $referenciaId = null): IngresoItem
    {
        $codigo = trim($codigo);

        return $this->enBorrador($ingreso, function (Ingreso $bloqueado) use ($codigo, $referenciaId) {
            $referencia = $this->referenciaPorCodigo($bloqueado->proveedor_id, $codigo, $referenciaId);

            if ($referencia === null) {
                throw new ReglaInventarioException(
                    "El código {$codigo} no es una referencia de este proveedor.",
                    404,
                    ['motivo' => 'referencia_inexistente', 'codigo' => $codigo],
                );
            }

            // La fila del ingreso ya está bloqueada: dos lecturas simultáneas no duplican la línea.
            $item = $bloqueado->items()->where('referencia_id', $referencia->id)->orderBy('id')->first();

            if ($item) {
                $item->increment('cantidad');
            } else {
                $item = $bloqueado->items()->create([
                    'referencia_id' => $referencia->id,
                    'cantidad' => 1,
                    'costo_unitario' => $this->ultimoCosto($referencia->id) ?? 0,
                    'descuento_porcentaje' => 0,
                    // S-22: el IVA de la línea se copia de la referencia y se corrige en la tabla.
                    'iva_porcentaje' => $referencia->iva_venta_porcentaje ?? 0,
                ]);
            }

            return $item->setRelation('referencia', $referencia);
        });
    }

    /**
     * S-25: entre las referencias del proveedor, el código leído puede ser el código de barras
     * (codigo_fabricante, exacto) o la referencia (codigo_referencia, exacto). Si coincide con
     * más de una referencia, 422 "Código ambiguo" con las opciones; con referencia_id se elige
     * una de ellas (debe ser una de las que coinciden).
     */
    private function referenciaPorCodigo(int $proveedorId, string $codigo, ?int $referenciaId): ?Referencia
    {
        $todas = Referencia::query()
            ->where('proveedor_id', $proveedorId)
            ->where(fn ($q) => $q->where('codigo_fabricante', $codigo)->orWhere('codigo_referencia', $codigo))
            ->when($referenciaId, fn ($q) => $q->whereKey($referenciaId))
            ->orderBy('id')
            ->limit(5)
            ->get(['id', 'codigo_referencia', 'codigo_fabricante', 'descripcion', 'iva_venta_porcentaje', 'activo']);
        $coinciden = $todas->where('activo', true)->values();

        // S-26: el código es de una referencia inactiva: no se agrega ni se ofrece crear otra.
        if ($coinciden->isEmpty() && $todas->isNotEmpty()) {
            throw new ReglaInventarioException('La referencia está inactiva; reactívela para usarla.', 422, [
                'motivo' => 'referencia_inactiva',
                'codigo' => $codigo,
                'referencia_id' => $todas->first()->id,
            ]);
        }

        if ($coinciden->count() > 1) {
            throw new ReglaInventarioException("Código ambiguo: {$codigo} coincide con varias referencias de este proveedor. Elija una.", 422, [
                'motivo' => 'codigo_ambiguo',
                'codigo' => $codigo,
                'opciones' => $coinciden->map(fn (Referencia $r) => $r->only('id', 'codigo_referencia', 'codigo_fabricante', 'descripcion'))->values(),
            ]);
        }

        return $coinciden->first();
    }

    public function agregarItem(Ingreso $ingreso, array $datos): IngresoItem
    {
        // S-26: una referencia inactiva no entra a un ingreso.
        if (Referencia::whereKey($datos['referencia_id'])->where('activo', false)->exists()) {
            throw new ReglaInventarioException('La referencia está inactiva; reactívela para usarla.', 422, ['motivo' => 'referencia_inactiva']);
        }

        // S-22: sin IVA explícito, la línea toma el de la referencia (como en ventas).
        $datos['iva_porcentaje'] ??= Referencia::whereKey($datos['referencia_id'])->value('iva_venta_porcentaje') ?? 0;

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

            // RF-20: una línea sin costo (0, como queda al escanear algo sin historial) no se confirma.
            $sinCosto = $items->filter(fn (IngresoItem $i) => (float) $i->costo_unitario <= 0);
            if ($sinCosto->isNotEmpty()) {
                $codigos = Referencia::query()->whereKey($sinCosto->pluck('referencia_id'))->orderBy('codigo_referencia')->pluck('codigo_referencia');

                throw new ReglaInventarioException(
                    'No se puede confirmar: falta el costo de '.$codigos->implode(', ').'. Escríbalo en la tabla de ítems.',
                    422,
                );
            }

            // RF-09 (ajuste): el total se calcula con el descuento prorrateado y queda fijo (RF-16).
            $totales = TotalesIngreso::calcular($items, $ingreso->descuento_documento, $ingreso->descuento_documento_porcentaje);
            $totales->exigirDescuentoValido();
            $this->exigirVencimiento($ingreso);

            // S-22: un documento en $0 (mercancía obsequiada) se acepta de contado, no a crédito.
            if ($ingreso->esCredito() && bccomp($totales->total, '0', 2) === 0) {
                throw new ReglaInventarioException('Un ingreso a crédito no puede confirmarse con total $0.', 422);
            }

            $codigos = $this->codigos->reservar($items->sum('cantidad'));
            $this->inventario->darEntrada($ingreso, $items, $codigos, $usuario->id);

            $ingreso->estado = EstadoIngreso::Confirmado;
            $ingreso->confirmado_at = now();
            $ingreso->confirmado_por = $usuario->id;
            $ingreso->total = $totales->total;
            // Confirmado, el descuento queda fijo y solo en pesos (S-22).
            $ingreso->descuento_documento = $totales->descuentoDocumento;
            $ingreso->descuento_documento_porcentaje = null;
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

    /**
     * RF-16 / S-21: a contado no hay vencimiento (se guarda null aunque llegue). A crédito el
     * vencimiento se digita; el plazo del proveedor solo lo prellena la pantalla.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function condicionDePago(array $datos, ?Ingreso $actual): array
    {
        $forma = isset($datos['forma_pago'])
            ? ($datos['forma_pago'] instanceof FormaPago ? $datos['forma_pago'] : FormaPago::from($datos['forma_pago']))
            : ($actual?->forma_pago ?? FormaPago::Contado);

        if ($forma === FormaPago::Contado) {
            $datos['vence_el'] = null;
        }

        return $datos;
    }

    /**
     * S-22: el descuento del documento llega en pesos o en %. En pesos, borra el %; en %, se
     * guarda el % (los pesos los recalcula TotalesIngreso con las líneas) y los pesos quedan en 0
     * hasta confirmar.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function descuentoDocumento(array $datos): array
    {
        if (array_key_exists('descuento_documento_porcentaje', $datos) && $datos['descuento_documento_porcentaje'] !== null) {
            $datos['descuento_documento'] = 0;
        } elseif (array_key_exists('descuento_documento', $datos) || array_key_exists('descuento_documento_porcentaje', $datos)) {
            $datos['descuento_documento'] ??= 0;
            $datos['descuento_documento_porcentaje'] = null;
        }

        return $datos;
    }

    /** RF-16: un ingreso a crédito no se confirma sin vencimiento o con uno anterior a la fecha. */
    private function exigirVencimiento(Ingreso $ingreso): void
    {
        if (! $ingreso->esCredito()) {
            return;
        }

        if ($ingreso->vence_el === null || $ingreso->vence_el->lt($ingreso->fecha)) {
            throw new ReglaInventarioException(
                'A crédito, el ingreso necesita una fecha de vencimiento igual o posterior a la del documento.',
                422,
                ['errors' => ['vence_el' => ['Escriba el vencimiento que trae el documento.']]],
            );
        }
    }

    /** Último costo con que entró la referencia en un ingreso confirmado (null si nunca entró). */
    private function ultimoCosto(int $referenciaId): ?string
    {
        return IngresoItem::query()
            ->join('ingresos', 'ingresos.id', '=', 'ingreso_items.ingreso_id')
            ->where('ingreso_items.referencia_id', $referenciaId)
            ->where('ingresos.estado', EstadoIngreso::Confirmado)
            ->where('ingreso_items.costo_unitario', '>', 0)
            ->orderByDesc('ingreso_items.id')
            ->value('ingreso_items.costo_unitario');
    }

    /** @param array{tipo:TipoIngreso, proveedor_id:int, numero_documento:string} $datos */
    private function buscarDocumento(array $datos): ?Ingreso
    {
        return Ingreso::query()
            ->where('proveedor_id', $datos['proveedor_id'])
            ->where('tipo', $datos['tipo'])
            ->where('numero_documento', $datos['numero_documento'])
            ->first();
    }

    private function reabrir(Ingreso $ingreso): Ingreso
    {
        if ($ingreso->esBorrador()) {
            return $ingreso;
        }

        $tipo = $ingreso->tipo === TipoIngreso::Factura ? 'La factura' : 'La remisión';
        $cuando = $ingreso->confirmado_at?->format('d/m/Y');

        throw new ReglaInventarioException(
            "{$tipo} {$ingreso->numero_documento} de este proveedor ya se ingresó (ingreso #{$ingreso->id}, confirmado el {$cuando}). No se ingresa dos veces.",
            422,
            ['motivo' => 'documento_confirmado', 'ingreso_id' => $ingreso->id],
        );
    }

    private function exigirBorrador(Ingreso $ingreso): void
    {
        if (! $ingreso->esBorrador()) {
            throw new ReglaInventarioException('El ingreso ya está confirmado y no se puede modificar.');
        }
    }
}
