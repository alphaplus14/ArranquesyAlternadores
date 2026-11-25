<?php

namespace App\Services;

use App\Enums\EstadoIngreso;
use App\Enums\EstadoUnidad;
use App\Enums\FormaPago;
use App\Enums\TipoIngreso;
use App\Enums\TipoMovimiento;
use App\Exceptions\ReglaInventarioException;
use App\Models\Auditoria;
use App\Models\Ingreso;
use App\Models\IngresoCorreccion;
use App\Models\IngresoItem;
use App\Models\Referencia;
use App\Models\Unidad;
use App\Models\User;
use App\Support\TotalesIngreso;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RF-05: ingreso por remisión, ítem por ítem, al mismo inventario.
 * RF-04: la factura de compra se ingresa a mano, igual que la remisión (sin QR ni XML).
 * RF-09 / RF-10: descuento de la factura y líneas sin IVA.
 * RF-20: los ítems se agregan escaneando el código de referencia del proveedor del ingreso.
 * RF-16: forma de pago y vencimiento digitado; al confirmar se guarda el total (RF-09, ajuste).
 * RF-03: al confirmar se crean las unidades con su código de barras.
 * RF-19: una factura se liga a remisiones del mismo proveedor para no duplicar inventario ni deuda.
 * RF-28 (S-58, S-60, S-61): corrección de un ingreso confirmado, con vista previa sin escritura.
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

            // RF-09 / S-66: en el borrador el descuento se fija en cualquier momento, aun sin líneas.
            // Que no supere el subtotal neto se exige al confirmar, cuando el total queda fijo.

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

    /**
     * RF-19: remisiones confirmadas del mismo proveedor que todavía no tienen factura, por fecha.
     *
     * @return Collection<int, Ingreso>
     */
    public function remisionesDisponibles(Ingreso $factura): Collection
    {
        $this->exigirFactura($factura);

        return Ingreso::query()
            ->where('proveedor_id', $factura->proveedor_id)
            ->where('tipo', TipoIngreso::Remision)
            ->where('estado', EstadoIngreso::Confirmado)
            ->whereNull('factura_id')
            ->withSum('items as unidades', 'cantidad')
            ->orderBy('fecha')->orderBy('id')
            ->get();
    }

    /**
     * RF-19: liga remisiones a una factura en borrador. Solo remisiones confirmadas, del mismo
     * proveedor y sin otra factura. Cada vínculo queda en la auditoría (Ingreso es Auditable).
     *
     * @param  list<int>  $remisionIds
     */
    public function ligarRemisiones(Ingreso $factura, array $remisionIds): Ingreso
    {
        return $this->enBorrador($factura, function (Ingreso $bloqueada) use ($remisionIds) {
            $this->exigirFactura($bloqueada);

            $remisiones = Ingreso::query()->whereKey($remisionIds)->orderBy('id')->lockForUpdate()->get();
            if ($remisiones->count() !== count(array_unique($remisionIds))) {
                throw new ReglaInventarioException('Alguna remisión no existe.', 422);
            }

            foreach ($remisiones as $remision) {
                $problema = match (true) {
                    $remision->tipo !== TipoIngreso::Remision => 'no es una remisión',
                    (int) $remision->proveedor_id !== (int) $bloqueada->proveedor_id => 'es de otro proveedor',
                    $remision->esBorrador() => 'todavía no está confirmada',
                    $remision->factura_id !== null && (int) $remision->factura_id !== $bloqueada->id => 'ya está ligada a otra factura',
                    default => null,
                };

                if ($problema) {
                    throw new ReglaInventarioException("La remisión {$remision->numero_documento} {$problema}.", 422);
                }
            }

            foreach ($remisiones->whereNull('factura_id') as $remision) {
                $remision->factura_id = $bloqueada->id;
                $remision->save();
            }

            return $bloqueada;
        });
    }

    /** RF-19: mientras la factura es borrador, una remisión ligada se puede desligar. */
    public function desligarRemision(Ingreso $factura, Ingreso $remision): Ingreso
    {
        return $this->enBorrador($factura, function (Ingreso $bloqueada) use ($remision) {
            $remision = Ingreso::query()->lockForUpdate()->findOrFail($remision->id);

            if ((int) $remision->factura_id !== $bloqueada->id) {
                throw new ReglaInventarioException("La remisión {$remision->numero_documento} no está ligada a esta factura.", 422);
            }

            $remision->factura_id = null;
            $remision->save();

            return $bloqueada;
        });
    }

    /**
     * RF-19: copia a la factura las líneas de sus remisiones ligadas, sumadas por referencia.
     * Si la factura ya tiene esa referencia, solo ajusta la cantidad; si no, crea la línea con
     * el costo, el descuento y el IVA de la línea más reciente de las remisiones. Repetirlo no
     * duplica nada. Después logística corrige según la factura.
     */
    public function prellenar(Ingreso $factura): Ingreso
    {
        return $this->enBorrador($factura, function (Ingreso $bloqueada) {
            $this->exigirFactura($bloqueada);

            $lineas = IngresoItem::query()
                ->whereIn('ingreso_id', $bloqueada->remisiones()->select('id'))
                ->orderBy('id')
                ->get()
                ->groupBy('referencia_id');

            if ($lineas->isEmpty()) {
                throw new ReglaInventarioException('La factura no tiene remisiones ligadas para prellenar.', 422);
            }

            $existentes = $bloqueada->items()->orderBy('id')->get()->keyBy('referencia_id');

            foreach ($lineas as $referenciaId => $deRemisiones) {
                $cantidad = $deRemisiones->sum('cantidad');
                $modelo = $deRemisiones->last();

                if ($linea = $existentes->get($referenciaId)) {
                    $linea->update(['cantidad' => $cantidad]);
                } else {
                    $bloqueada->items()->create([
                        'referencia_id' => $referenciaId,
                        'cantidad' => $cantidad,
                        'costo_unitario' => $modelo->costo_unitario,
                        'descuento_porcentaje' => $modelo->descuento_porcentaje,
                        'iva_porcentaje' => $modelo->iva_porcentaje,
                    ]);
                }
            }

            return $bloqueada;
        });
    }

    /**
     * RF-05: confirma el ingreso, crea las unidades y suma existencia. Queda quién confirmó.
     * RF-19: en una factura con remisiones ligadas, solo entran las unidades no cubiertas; si se
     * facturó menos de lo recibido, la diferencia se acepta explícitamente con un motivo.
     */
    public function confirmar(Ingreso $ingreso, User $usuario, bool $aceptarDiferencia = false, ?string $motivo = null): Ingreso
    {
        return DB::transaction(function () use ($ingreso, $usuario, $aceptarDiferencia, $motivo) {
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

            if ($ingreso->tipo === TipoIngreso::Factura) {
                $this->cubrirConRemisiones($ingreso, $items, $aceptarDiferencia, $motivo);
            }

            $codigos = $this->codigos->reservar($items->sum(fn (IngresoItem $i) => InventarioService::unidadesNuevas($i)));
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
     * RF-19: por referencia, cantidad_cubierta = lo recibido por las remisiones ligadas, hasta lo
     * facturado, repartido entre las líneas de la factura en orden. Si queda algo recibido sin
     * facturar (menos cantidad, o una referencia que no aparece), exige aceptar la diferencia.
     *
     * @param  Collection<int, IngresoItem>  $items  líneas de la factura, por id
     */
    private function cubrirConRemisiones(Ingreso $factura, Collection $items, bool $aceptar, ?string $motivo): void
    {
        $cobertura = $this->cobertura($factura, $items);

        foreach ($items as $item) {
            $cubierta = $cobertura['cubiertas'][$item->id] ?? 0;
            if ($cubierta === 0) {
                continue;
            }
            // Dato calculado al confirmar, no una edición: no pasa por la auditoría.
            IngresoItem::query()->whereKey($item->id)->update(['cantidad_cubierta' => $cubierta]);
            $item->setAttribute('cantidad_cubierta', $cubierta)->syncOriginalAttribute('cantidad_cubierta');
        }

        if ($cobertura['diferencias'] !== []) {
            $this->aceptarDiferencia($factura, $cobertura['diferencias'], $aceptar, $motivo);
        }
    }

    /**
     * RF-19: por referencia, cantidad_cubierta = lo recibido por las remisiones ligadas, hasta lo
     * facturado, repartido entre las líneas de la factura en orden. `diferencias` = lo recibido que
     * quedó sin facturar (menos cantidad, o una referencia que no aparece). Solo calcula: no escribe.
     * RF-28: la corrección lo usa con las líneas finales (objetos con id, referencia_id y cantidad).
     *
     * @param  iterable<object>  $lineas
     * @return array{cubiertas: array<int|string, int>, diferencias: list<array<string, mixed>>}
     */
    private function cobertura(Ingreso $factura, iterable $lineas): array
    {
        $recibido = IngresoItem::query()
            ->whereIn('ingreso_id', $factura->remisiones()->select('id'))
            ->groupBy('referencia_id')
            ->select('referencia_id', DB::raw('SUM(cantidad) as cantidad'))
            ->pluck('cantidad', 'referencia_id')
            ->map(fn ($c) => (int) $c)
            ->all();

        if ($recibido === []) {
            return ['cubiertas' => [], 'diferencias' => []];
        }

        $porCubrir = $recibido;
        $cubiertas = [];
        foreach ($lineas as $linea) {
            $cubierta = min((int) $linea->cantidad, $porCubrir[$linea->referencia_id] ?? 0);
            $cubiertas[$linea->id] = $cubierta;
            if ($cubierta > 0) {
                $porCubrir[$linea->referencia_id] -= $cubierta;
            }
        }

        $faltantes = array_filter($porCubrir);
        if ($faltantes === []) {
            return ['cubiertas' => $cubiertas, 'diferencias' => []];
        }

        $codigos = Referencia::query()->whereKey(array_keys($faltantes))->pluck('codigo_referencia', 'id');
        ksort($faltantes);
        $diferencias = collect($faltantes)->map(fn ($sinFacturar, $referenciaId) => [
            'referencia_id' => $referenciaId,
            'codigo_referencia' => $codigos[$referenciaId] ?? null,
            'recibido' => $recibido[$referenciaId],
            'facturado' => $recibido[$referenciaId] - $sinFacturar,
        ])->values()->all();

        return ['cubiertas' => $cubiertas, 'diferencias' => $diferencias];
    }

    /** RF-19: la factura cubre menos de lo recibido: se acepta explícitamente con un motivo. */
    private function aceptarDiferencia(Ingreso $factura, array $diferencias, bool $aceptar, ?string $motivo): void
    {
        if (! $aceptar) {
            $detalle = collect($diferencias)->map(fn ($d) => "{$d['codigo_referencia']}: recibido {$d['recibido']}, facturado {$d['facturado']}")->implode('; ');

            throw new ReglaInventarioException(
                "La factura cubre menos de lo recibido por las remisiones ({$detalle}). Para confirmar, acepte la diferencia con un motivo.",
                422,
                ['motivo' => 'diferencia_remision', 'diferencias' => $diferencias],
            );
        }

        if (trim((string) $motivo) === '') {
            throw new ReglaInventarioException(
                'Escriba el motivo para aceptar la diferencia con las remisiones.',
                422,
                ['errors' => ['motivo' => ['Escriba por qué se acepta la diferencia.']]],
            );
        }

        $factura->diferencia_aceptada = true;
        $factura->diferencia_motivo = trim($motivo);
    }

    /* ───────────────── RF-28: corrección de un ingreso confirmado (S-58, S-60, S-61) ───────────────── */

    /** S-58: lo que identifica el documento no se corrige. */
    public const CAMPOS_NO_CORREGIBLES = ['proveedor_id' => 'el proveedor', 'tipo' => 'el tipo', 'id' => 'el id'];

    /**
     * RF-28: vista previa de una corrección, sin escribir nada: totales, unidades a crear y a retirar
     * por referencia, advertencias (etiquetas impresas, diferencia con remisiones) y errores
     * (unidades vendidas). Las validaciones de datos responden 422 igual que la corrección.
     *
     * @return array<string, mixed>
     */
    public function vistaPreviaCorreccion(Ingreso $ingreso, array $datos): array
    {
        $plan = $this->planificarCorreccion(Ingreso::query()->findOrFail($ingreso->id), $datos);

        return $this->resumenCorreccion($plan, $datos);
    }

    /**
     * RF-28 (S-58): única vía para cambiar un ingreso confirmado. En UNA transacción, con el ingreso
     * y sus referencias bloqueados (en orden de id, como darEntrada): repite todas las validaciones
     * de la vista previa, aplica el estado final (encabezado y líneas, auditados), recalcula total y
     * deuda, ajusta las unidades vía InventarioService y deja la fila en ingreso_correcciones.
     */
    public function corregir(Ingreso $ingreso, array $datos, string $motivo, User $usuario): IngresoCorreccion
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 5 || mb_strlen($motivo) > 500) {
            throw new ReglaInventarioException('Escriba el motivo de la corrección (entre 5 y 500 caracteres).', 422, ['errors' => ['motivo' => ['Escriba el motivo de la corrección (entre 5 y 500 caracteres).']]]);
        }

        return DB::transaction(function () use ($ingreso, $datos, $motivo, $usuario) {
            $ingreso = Ingreso::query()->lockForUpdate()->findOrFail($ingreso->id);
            $referencias = $ingreso->items()->pluck('referencia_id')
                ->merge(collect($datos['items'] ?? [])->pluck('referencia_id')->filter())
                ->map(fn ($id) => (int) $id)->unique()->sort()->values();
            Referencia::query()->whereKey($referencias)->orderBy('id')->lockForUpdate()->get(['id']);

            $plan = $this->planificarCorreccion($ingreso, $datos);

            if ($plan['errores'] !== []) {
                throw new ReglaInventarioException($plan['errores'][0]['mensaje'], 422, ['errores' => $plan['errores']]);
            }
            if ($plan['con_etiqueta_impresa'] > 0 && ! ($datos['confirmar_etiquetas_impresas'] ?? false)) {
                throw new ReglaInventarioException(
                    "Hay que retirar {$plan['con_etiqueta_impresa']} unidades que ya tienen la etiqueta impresa. Retire esas etiquetas de la mercancía y confirme.",
                    422,
                    ['motivo' => 'etiquetas_impresas', 'con_etiqueta_impresa' => $plan['con_etiqueta_impresa']],
                );
            }
            if ($plan['requiere_aceptar_diferencia']) {
                $this->aceptarDiferencia($ingreso, $plan['diferencias'], (bool) ($datos['aceptar_diferencia'] ?? false), $datos['diferencia_motivo'] ?? null);
            }

            $enc = $plan['encabezado'];
            if ($enc['numero_documento'] !== $ingreso->numero_documento && Ingreso::query()
                ->where('proveedor_id', $ingreso->proveedor_id)->where('tipo', $ingreso->tipo)
                ->where('numero_documento', $enc['numero_documento'])->whereKeyNot($ingreso->id)->exists()) {
                throw new ReglaInventarioException('Ya hay otro ingreso de ese proveedor con el mismo tipo y número de documento.', 422, ['errors' => ['numero_documento' => ['Ese número ya está registrado para este proveedor.']]]);
            }

            // Encabezado y total: Auditable deja cada campo cambiado (RF-11).
            $totalAnterior = $ingreso->total;
            $ingreso->permitirCorreccion()->fill([
                'numero_documento' => $enc['numero_documento'],
                'fecha' => $enc['fecha'],
                'forma_pago' => $enc['forma_pago'],
                'vence_el' => $enc['vence_el'],
            ]);
            $ingreso->total = $plan['totales']->total;
            $ingreso->descuento_documento = $plan['totales']->descuentoDocumento;
            $ingreso->descuento_documento_porcentaje = null;
            try {
                $ingreso->save();
            } catch (UniqueConstraintViolationException) {
                throw new ReglaInventarioException('Ya hay otro ingreso de ese proveedor con el mismo tipo y número de documento.', 422);
            }

            // Líneas: las existentes se actualizan (auditadas); las nuevas se crean con su fila '*'
            // en la auditoría; una línea quitada queda en cantidad 0 (sus unidades la siguen citando).
            $existentes = $ingreso->items()->get()->keyBy('id');
            $idDe = [];
            foreach ($plan['lineas'] as $clave => $l) {
                $valores = Arr::only($l, ['cantidad', 'costo_unitario', 'descuento_porcentaje', 'iva_porcentaje']);
                if ($l['id'] !== null) {
                    $existentes[$l['id']]->fill($valores)->save();
                    $idDe[$clave] = $l['id'];

                    continue;
                }
                $nuevo = $ingreso->items()->create(['referencia_id' => $l['referencia_id'], ...$valores]);
                $idDe[$clave] = $nuevo->id;
                Auditoria::insert([
                    'tabla' => 'ingreso_items',
                    'registro_id' => $nuevo->id,
                    'campo' => '*',
                    'valor_anterior' => null,
                    'valor_nuevo' => json_encode(['ingreso_id' => $ingreso->id, 'referencia_id' => $l['referencia_id'], ...$valores, 'origen' => 'correccion'], JSON_UNESCAPED_UNICODE),
                    'usuario_id' => $usuario->id,
                    'created_at' => now(),
                ]);
            }

            // RF-19: cantidad_cubierta recalculada (dato derivado, como al confirmar).
            foreach ($plan['lineas'] as $clave => $l) {
                if ($l['cantidad_cubierta'] !== $l['cubierta_antes']) {
                    IngresoItem::query()->whereKey($idDe[$clave])->update(['cantidad_cubierta' => $l['cantidad_cubierta']]);
                }
            }

            $despues = $plan['despues'];
            foreach ($despues['lineas'] as $i => $l) {
                $despues['lineas'][$i]['id'] = $idDe[$l['clave']];
                unset($despues['lineas'][$i]['clave']);
            }
            $antes = $plan['antes'];
            foreach ($antes['lineas'] as $i => $l) {
                unset($antes['lineas'][$i]['clave']);
            }

            $correccion = IngresoCorreccion::create([
                'ingreso_id' => $ingreso->id,
                'usuario_id' => $usuario->id,
                'motivo' => $motivo,
                'antes' => $antes,
                'despues' => $despues,
                'unidades_creadas' => $plan['unidades_crear'],
                'unidades_retiradas' => $plan['unidades_retirar'],
                'total_anterior' => $totalAnterior,
                'total_nuevo' => $plan['totales']->total,
            ]);

            // S-60: retirar primero las disponibles sin etiqueta impresa; nunca una vendida.
            $aRetirar = collect();
            foreach ($plan['lineas'] as $clave => $l) {
                if ($l['retirar'] === 0) {
                    continue;
                }
                $unidades = $this->unidadesVigentes($idDe[$clave])
                    ->where('estado', EstadoUnidad::Disponible)
                    ->orderByRaw('etiqueta_impresa_at IS NOT NULL')->orderBy('id')
                    ->limit($l['retirar'])->lockForUpdate()
                    ->get(['id', 'referencia_id', 'codigo_barras', 'estado', 'etiqueta_impresa_at']);
                if ($unidades->count() !== $l['retirar']) {
                    throw new ReglaInventarioException("No hay suficientes unidades disponibles de {$l['codigo_referencia']} para retirar.", 422);
                }
                $aRetirar = $aRetirar->concat($unidades);
            }
            $this->inventario->retirarUnidadesPorCorreccion($aRetirar, $correccion, $usuario->id);

            $porLinea = [];
            $referenciaDe = [];
            foreach ($plan['lineas'] as $clave => $l) {
                if ($l['crear'] > 0) {
                    $porLinea[$idDe[$clave]] = $l['crear'];
                    $referenciaDe[$idDe[$clave]] = $l['referencia_id'];
                }
            }
            $codigos = $this->codigos->reservar(array_sum($porLinea));
            $this->inventario->agregarUnidadesPorCorreccion($porLinea, $referenciaDe, $codigos, $correccion, $usuario->id);

            return $correccion;
        });
    }

    /**
     * Calcula el estado final de la corrección sin escribir: encabezado, líneas, totales,
     * cobertura de remisiones y unidades por línea. Lanza 422 en los errores de datos; los de
     * inventario (unidades vendidas) los devuelve en `errores` para que la vista previa los muestre.
     *
     * @return array<string, mixed>
     */
    private function planificarCorreccion(Ingreso $ingreso, array $datos): array
    {
        $prohibidos = array_intersect_key(self::CAMPOS_NO_CORREGIBLES, $datos);
        if ($prohibidos !== []) {
            throw new ReglaInventarioException(
                'En una corrección no se cambia '.implode(', ', $prohibidos).' del ingreso. Si el documento es de otro proveedor o de otro tipo, corrija las cantidades y regístrelo aparte.',
                422,
                ['errors' => array_map(fn () => ['Este dato no se corrige.'], $prohibidos)],
            );
        }
        if ($ingreso->esBorrador()) {
            throw new ReglaInventarioException('Solo se corrige un ingreso confirmado; un borrador se edita directamente.', 422);
        }

        $items = $ingreso->items()->with('referencia:id,codigo_referencia')->orderBy('id')->get();

        // Encabezado final.
        $forma = $datos['forma_pago'] ?? $ingreso->forma_pago->value;
        $forma = $forma instanceof FormaPago ? $forma->value : $forma;
        $enc = [
            'numero_documento' => isset($datos['numero_documento']) ? Ingreso::normalizarNumero($datos['numero_documento']) : $ingreso->numero_documento,
            'fecha' => isset($datos['fecha']) ? Carbon::parse($datos['fecha'])->toDateString() : $ingreso->fecha->toDateString(),
            'forma_pago' => $forma,
            'vence_el' => array_key_exists('vence_el', $datos)
                ? ($datos['vence_el'] ? Carbon::parse($datos['vence_el'])->toDateString() : null)
                : $ingreso->vence_el?->toDateString(),
        ];
        if ($forma === FormaPago::Contado->value) {
            $enc['vence_el'] = null;
        }
        if ($enc['numero_documento'] === '') {
            throw new ReglaInventarioException('Escriba el número del documento.', 422, ['errors' => ['numero_documento' => ['Escriba el número del documento.']]]);
        }

        // Descuento del documento: en pesos (como quedó al confirmar) o en % (se convierte a pesos).
        $descPesos = (string) $ingreso->descuento_documento;
        $descPct = null;
        if (array_key_exists('descuento_documento_porcentaje', $datos) && $datos['descuento_documento_porcentaje'] !== null) {
            $descPct = (string) $datos['descuento_documento_porcentaje'];
        } elseif (array_key_exists('descuento_documento', $datos)) {
            $descPesos = (string) ($datos['descuento_documento'] ?? 0);
        }

        // Líneas: estado actual y estado final.
        $lineas = [];
        foreach ($items as $it) {
            $lineas['i'.$it->id] = [
                'id' => $it->id,
                'referencia_id' => (int) $it->referencia_id,
                'codigo_referencia' => $it->referencia?->codigo_referencia,
                'cantidad' => (int) $it->cantidad,
                'costo_unitario' => self::dosDecimales($it->costo_unitario),
                'descuento_porcentaje' => self::dosDecimales($it->descuento_porcentaje),
                'iva_porcentaje' => self::dosDecimales($it->iva_porcentaje),
                'cubierta_antes' => (int) $it->cantidad_cubierta,
            ];
        }
        $antesLineas = $lineas;

        if (array_key_exists('items', $datos)) {
            $enviadas = [];
            foreach (array_values($datos['items'] ?? []) as $n => $l) {
                $id = $l['id'] ?? null;
                if ($id !== null) {
                    $clave = 'i'.$id;
                    if (! isset($lineas[$clave])) {
                        throw new ReglaInventarioException("La línea #{$id} no es de este ingreso.", 422);
                    }
                    if (isset($enviadas[$clave])) {
                        throw new ReglaInventarioException("La línea #{$id} viene repetida.", 422);
                    }
                    if (isset($l['referencia_id']) && (int) $l['referencia_id'] !== $lineas[$clave]['referencia_id']) {
                        throw new ReglaInventarioException('La referencia de una línea no se cambia: quite esa línea y agregue otra con la referencia correcta.', 422, ['linea_id' => (int) $id]);
                    }
                    $enviadas[$clave] = true;
                    foreach (['cantidad', 'costo_unitario', 'descuento_porcentaje', 'iva_porcentaje'] as $campo) {
                        if (array_key_exists($campo, $l)) {
                            $lineas[$clave][$campo] = $campo === 'cantidad' ? (int) $l[$campo] : self::dosDecimales($l[$campo]);
                        }
                    }

                    continue;
                }

                $referencia = $this->referenciaParaCorreccion($ingreso, (int) ($l['referencia_id'] ?? 0));
                $lineas['n'.$n] = [
                    'id' => null,
                    'referencia_id' => $referencia->id,
                    'codigo_referencia' => $referencia->codigo_referencia,
                    'cantidad' => (int) ($l['cantidad'] ?? 0),
                    'costo_unitario' => self::dosDecimales($l['costo_unitario'] ?? $this->ultimoCosto($referencia->id) ?? 0),
                    'descuento_porcentaje' => self::dosDecimales($l['descuento_porcentaje'] ?? 0),
                    'iva_porcentaje' => self::dosDecimales($l['iva_porcentaje'] ?? $referencia->iva_venta_porcentaje ?? 0),
                    'cubierta_antes' => 0,
                ];
            }
            // Las líneas existentes que no llegan se quitan: quedan en cantidad 0.
            foreach ($lineas as $clave => $l) {
                if ($l['id'] !== null && ! isset($enviadas[$clave])) {
                    $lineas[$clave]['cantidad'] = 0;
                }
            }
        }

        $camposLinea = ['cantidad', 'costo_unitario', 'descuento_porcentaje', 'iva_porcentaje'];
        $lineasCambian = collect($lineas)->contains(fn ($l, $clave) => ! isset($antesLineas[$clave])
            || Arr::only($l, $camposLinea) != Arr::only($antesLineas[$clave], $camposLinea));
        $condicionCambia = $enc['forma_pago'] !== $ingreso->forma_pago->value
            || $enc['vence_el'] !== $ingreso->vence_el?->toDateString();

        // S-61: una remisión cubierta por una factura solo corrige número y fecha.
        if ($ingreso->tipo === TipoIngreso::Remision && $ingreso->factura_id !== null) {
            $descuentoCambia = $descPct !== null || bccomp($descPesos, (string) $ingreso->descuento_documento, 2) !== 0;
            if ($lineasCambian || $condicionCambia || $descuentoCambia) {
                $factura = Ingreso::query()->whereKey($ingreso->factura_id)->value('numero_documento');
                throw new ReglaInventarioException(
                    "La remisión {$ingreso->numero_documento} está cubierta por la factura {$factura}: solo se corrigen el número y la fecha. Sus cantidades y costos mandan en la factura.",
                    422,
                    ['motivo' => 'remision_cubierta'],
                );
            }
        }

        $activas = array_filter($lineas, fn ($l) => $l['cantidad'] > 0);
        if ($activas === []) {
            throw new ReglaInventarioException('El ingreso no puede quedar sin líneas.', 422);
        }
        $sinCosto = array_filter($activas, fn ($l) => bccomp($l['costo_unitario'], '0', 2) <= 0);
        if ($sinCosto !== []) {
            throw new ReglaInventarioException('Falta el costo de '.implode(', ', array_column($sinCosto, 'codigo_referencia')).'.', 422);
        }

        // Totales (RF-09, S-22) con el cálculo único.
        $objetos = collect($lineas)->map(fn ($l, $clave) => (object) [...$l, 'id' => $clave])->values();
        $totales = TotalesIngreso::calcular($objetos, $descPesos, $descPct);
        $totales->exigirDescuentoValido();
        if ($enc['forma_pago'] === FormaPago::Credito->value) {
            if (bccomp($totales->total, '0', 2) === 0) {
                throw new ReglaInventarioException('Un ingreso a crédito no puede quedar con total $0.', 422);
            }
            if ($enc['vence_el'] === null || $enc['vence_el'] < $enc['fecha']) {
                throw new ReglaInventarioException(
                    'A crédito, el ingreso necesita una fecha de vencimiento igual o posterior a la del documento.',
                    422,
                    ['errors' => ['vence_el' => ['Escriba el vencimiento que trae el documento.']]],
                );
            }
        }

        // S-61: factura con remisiones ligadas → cantidad_cubierta con las líneas finales.
        $diferencias = [];
        $requiereAceptar = false;
        $cubiertas = array_map(fn ($l) => $l['cubierta_antes'], $lineas);
        if ($ingreso->tipo === TipoIngreso::Factura && $ingreso->remisiones()->exists()) {
            $cobertura = $this->cobertura($ingreso, $objetos);
            $cubiertas = array_replace(array_map(fn () => 0, $lineas), $cobertura['cubiertas']);
            $diferencias = $cobertura['diferencias'];
            if ($diferencias !== []) {
                $previas = $this->cobertura($ingreso, collect($antesLineas)->map(fn ($l, $clave) => (object) [...$l, 'id' => $clave])->values())['diferencias'];
                $requiereAceptar = ! $ingreso->diferencia_aceptada || $diferencias != $previas;
            }
        }

        // S-60: unidades por línea = diferencia de unidadesNuevas (cantidad − cubierta) antes y después.
        $conteo = $this->conteoUnidades(array_filter(array_column($lineas, 'id')));
        $errores = [];
        $conEtiqueta = 0;
        foreach ($lineas as $clave => $l) {
            $lineas[$clave]['cantidad_cubierta'] = $cubiertas[$clave] ?? 0;
            $c = $l['id'] !== null ? ($conteo[$l['id']] ?? ['vigentes' => 0, 'sin_etiqueta' => 0, 'con_etiqueta' => 0]) : ['vigentes' => 0, 'sin_etiqueta' => 0, 'con_etiqueta' => 0];
            $despues = max($l['cantidad'] - $lineas[$clave]['cantidad_cubierta'], 0);
            $diferencia = $despues - $c['vigentes'];
            $lineas[$clave]['crear'] = max($diferencia, 0);
            $lineas[$clave]['retirar'] = max(-$diferencia, 0);
            $lineas[$clave]['con_etiqueta_impresa'] = 0;

            if ($diferencia < 0) {
                $retirables = $c['sin_etiqueta'] + $c['con_etiqueta'];
                if (-$diferencia > $retirables) {
                    $fuera = $c['vigentes'] - $retirables;
                    $minimo = $l['cantidad'] - $despues + ($c['vigentes'] - $retirables);
                    $errores[] = [
                        'tipo' => 'vendidas',
                        'referencia_id' => $l['referencia_id'],
                        'codigo_referencia' => $l['codigo_referencia'],
                        'mensaje' => $l['cantidad'] === 0
                            ? "No se puede quitar la línea de {$l['codigo_referencia']}: {$fuera} de sus unidades ya se vendieron o salieron del inventario."
                            : "No se puede bajar {$l['codigo_referencia']} a {$l['cantidad']}: {$fuera} unidades ya se vendieron o salieron del inventario (mínimo {$minimo}).",
                        'vendidas' => $fuera,
                    ];
                } else {
                    $lineas[$clave]['con_etiqueta_impresa'] = max(0, -$diferencia - $c['sin_etiqueta']);
                    $conEtiqueta += $lineas[$clave]['con_etiqueta_impresa'];
                }
            }
        }

        $descuentoFinal = $totales->descuentoDocumento;
        $encabezadoCambia = $enc['numero_documento'] !== $ingreso->numero_documento
            || $enc['fecha'] !== $ingreso->fecha->toDateString()
            || $condicionCambia
            || bccomp($descuentoFinal, (string) $ingreso->descuento_documento, 2) !== 0;
        if (! $encabezadoCambia && ! $lineasCambian) {
            throw new ReglaInventarioException('No hay cambios.', 422, ['motivo' => 'sin_cambios']);
        }

        $foto = fn (array $e, string $descuento, ?string $total, array $ls, bool $final) => [
            'encabezado' => [...$e, 'descuento_documento' => $descuento, 'total' => $total],
            'lineas' => array_values(array_map(fn ($l, $clave) => [
                'clave' => $clave,
                'id' => $l['id'],
                'referencia_id' => $l['referencia_id'],
                'codigo_referencia' => $l['codigo_referencia'],
                'cantidad' => $l['cantidad'],
                'costo_unitario' => $l['costo_unitario'],
                'descuento_porcentaje' => $l['descuento_porcentaje'],
                'iva_porcentaje' => $l['iva_porcentaje'],
                'cantidad_cubierta' => $final ? $l['cantidad_cubierta'] : $l['cubierta_antes'],
            ], $ls, array_keys($ls))),
        ];

        return [
            'encabezado' => $enc,
            'lineas' => $lineas,
            'totales' => $totales,
            'total_anterior' => $ingreso->total,
            'diferencias' => $diferencias,
            'requiere_aceptar_diferencia' => $requiereAceptar,
            'errores' => $errores,
            'con_etiqueta_impresa' => $conEtiqueta,
            'unidades_crear' => array_sum(array_column($lineas, 'crear')),
            'unidades_retirar' => array_sum(array_column($lineas, 'retirar')),
            'antes' => $foto([
                'numero_documento' => $ingreso->numero_documento,
                'fecha' => $ingreso->fecha->toDateString(),
                'forma_pago' => $ingreso->forma_pago->value,
                'vence_el' => $ingreso->vence_el?->toDateString(),
            ], self::dosDecimales($ingreso->descuento_documento), $ingreso->total === null ? null : self::dosDecimales($ingreso->total), $antesLineas, false),
            'despues' => $foto($enc, $descuentoFinal, $totales->total, $lineas, true),
        ];
    }

    /** @return array<string, mixed> */
    private function resumenCorreccion(array $plan, array $datos): array
    {
        $porReferencia = collect($plan['lineas'])
            ->groupBy('referencia_id')
            ->map(fn ($ls, $referenciaId) => [
                'referencia_id' => (int) $referenciaId,
                'codigo_referencia' => $ls->first()['codigo_referencia'],
                'crear' => $ls->sum('crear'),
                'retirar' => $ls->sum('retirar'),
                'con_etiqueta_impresa' => $ls->sum('con_etiqueta_impresa'),
            ])
            ->filter(fn ($r) => $r['crear'] > 0 || $r['retirar'] > 0)
            ->sortBy('codigo_referencia')->values();

        $advertencias = [];
        if ($plan['con_etiqueta_impresa'] > 0) {
            $advertencias[] = [
                'tipo' => 'con_etiqueta_impresa',
                'mensaje' => "Se retirarán {$plan['con_etiqueta_impresa']} unidades que ya tienen la etiqueta impresa: quite esas etiquetas de la mercancía.",
                'cantidad' => $plan['con_etiqueta_impresa'],
            ];
        }
        if ($plan['requiere_aceptar_diferencia']) {
            $detalle = collect($plan['diferencias'])->map(fn ($d) => "{$d['codigo_referencia']}: recibido {$d['recibido']}, facturado {$d['facturado']}")->implode('; ');
            $advertencias[] = [
                'tipo' => 'diferencia_remision',
                'mensaje' => "La factura cubre menos de lo recibido por las remisiones ({$detalle}). Hay que aceptar la diferencia con un motivo.",
            ];
        }

        return [
            'total_anterior' => $plan['total_anterior'],
            'total_nuevo' => $plan['totales']->total,
            'totales' => [
                'subtotal_neto' => $plan['totales']->subtotalNeto,
                'descuento_documento' => $plan['totales']->descuentoDocumento,
                'iva' => $plan['totales']->iva,
                'total' => $plan['totales']->total,
            ],
            'unidades_crear' => $plan['unidades_crear'],
            'unidades_retirar' => $plan['unidades_retirar'],
            'con_etiqueta_impresa' => $plan['con_etiqueta_impresa'],
            'requiere_confirmar_etiquetas' => $plan['con_etiqueta_impresa'] > 0,
            'requiere_aceptar_diferencia' => $plan['requiere_aceptar_diferencia'],
            'diferencias' => $plan['diferencias'],
            'por_referencia' => $porReferencia->all(),
            'advertencias' => $advertencias,
            'errores' => $plan['errores'],
            'antes' => $this->sinClave($plan['antes']),
            'despues' => $this->sinClave($plan['despues']),
        ];
    }

    private function sinClave(array $foto): array
    {
        $foto['lineas'] = array_map(fn ($l) => Arr::except($l, ['clave']), $foto['lineas']);

        return $foto;
    }

    /** S-58: la referencia de una línea nueva debe existir, estar activa y ser del proveedor del ingreso. */
    private function referenciaParaCorreccion(Ingreso $ingreso, int $referenciaId): Referencia
    {
        $referencia = Referencia::query()->find($referenciaId, ['id', 'proveedor_id', 'codigo_referencia', 'iva_venta_porcentaje', 'activo']);
        if (! $referencia) {
            throw new ReglaInventarioException('La referencia de la línea nueva no existe.', 422);
        }
        if (! $referencia->activo) {
            throw new ReglaInventarioException("La referencia {$referencia->codigo_referencia} está inactiva; reactívela para usarla.", 422, ['motivo' => 'referencia_inactiva']);
        }
        if ((int) $referencia->proveedor_id !== (int) $ingreso->proveedor_id) {
            throw new ReglaInventarioException("La referencia {$referencia->codigo_referencia} es de otro proveedor.", 422);
        }

        return $referencia;
    }

    /**
     * Unidades de una línea que siguen contando para ella: todas salvo las que retiró una
     * corrección anterior (las retiradas por un ajuste siguen siendo de la línea).
     */
    private function unidadesVigentes(int $itemId): Builder
    {
        return Unidad::query()->where('ingreso_item_id', $itemId)
            ->whereNotExists(fn ($q) => $q->from('movimientos_inventario as m')
                ->whereColumn('m.unidad_id', 'unidades.id')
                ->where('m.tipo', TipoMovimiento::Baja->value)
                ->where('m.origen_type', IngresoCorreccion::ORIGEN)
                ->selectRaw('1'));
    }

    /**
     * Por línea: unidades vigentes y disponibles con y sin etiqueta impresa. Una consulta agrupada.
     *
     * @param  list<int>  $itemIds
     * @return array<int, array{vigentes: int, sin_etiqueta: int, con_etiqueta: int}>
     */
    private function conteoUnidades(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return Unidad::query()->whereIn('ingreso_item_id', $itemIds)
            ->whereNotExists(fn ($q) => $q->from('movimientos_inventario as m')
                ->whereColumn('m.unidad_id', 'unidades.id')
                ->where('m.tipo', TipoMovimiento::Baja->value)
                ->where('m.origen_type', IngresoCorreccion::ORIGEN)
                ->selectRaw('1'))
            ->groupBy('ingreso_item_id')
            ->selectRaw("ingreso_item_id, COUNT(*) as vigentes,
                SUM(CASE WHEN estado = 'disponible' AND etiqueta_impresa_at IS NULL THEN 1 ELSE 0 END) as sin_etiqueta,
                SUM(CASE WHEN estado = 'disponible' AND etiqueta_impresa_at IS NOT NULL THEN 1 ELSE 0 END) as con_etiqueta")
            ->get()
            ->mapWithKeys(fn ($f) => [(int) $f->ingreso_item_id => [
                'vigentes' => (int) $f->vigentes,
                'sin_etiqueta' => (int) $f->sin_etiqueta,
                'con_etiqueta' => (int) $f->con_etiqueta,
            ]])
            ->all();
    }

    private static function dosDecimales(string|int|float|null $valor): string
    {
        return bcadd(is_string($valor) ? ($valor === '' ? '0' : $valor) : number_format((float) $valor, 2, '.', ''), '0', 2);
    }

    private function exigirFactura(Ingreso $ingreso): void
    {
        if ($ingreso->tipo !== TipoIngreso::Factura) {
            throw new ReglaInventarioException('Solo una factura se liga a remisiones.', 422);
        }
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
