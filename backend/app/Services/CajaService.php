<?php

namespace App\Services;

use App\Enums\GrupoCaja;
use App\Enums\Rol;
use App\Enums\TipoCuentaPago;
use App\Exceptions\ReglaInventarioException;
use App\Models\AperturaCaja;
use App\Models\CierreCaja;
use App\Models\Configuracion;
use App\Models\CuentaPago;
use App\Models\User;
use App\Support\RangoFechas;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RF-25 (S-49 a S-51, S-53): cálculo de la caja diaria y del cuadre mensual, configuración (meta),
 * apertura diaria de la caja y la regla "día cerrado". Las escrituras de movimientos y cierres
 * están en MovimientoCajaService y CierreCajaService.
 *
 * Días en hora de Bogotá (RangoFechas). Cada origen es UNA consulta agrupada por día y cuenta, para
 * cualquier rango (un día o un mes): ventas pagadas, ventas al cierre (crédito y contado sin detalle),
 * devoluciones, recaudo de cartera, pagos a proveedores, movimientos de caja y préstamos y abonos de
 * empleados (RF-27). Las aperturas, una consulta más por el índice único de fecha.
 *
 * Fórmulas (S-49, S-51):
 * - total_ingreso_dia = ventas de contado + ventas a crédito + otros ingresos. El recaudo de cartera
 *   y los ingresos externos no cuentan para la meta.
 * - efectivo_neto_dia = ventas en efectivo + recaudo en efectivo + otros ingresos en efectivo +
 *   ingresos externos + abonos de empleados en efectivo − devoluciones de contado − pagos a
 *   proveedores en efectivo − egresos − préstamos a empleados (RF-27, S-55: ni la apertura ni el
 *   abono por nómina cuentan; no son venta ni cuentan para la meta).
 * - efectivo_esperado = apertura del día + efectivo_neto_dia. La apertura es el efectivo físico que ya
 *   estaba en la caja al empezar; lo que llega durante el día desde la Caja General es ingreso
 *   externo (RF-26), así no se cuenta dos veces. No hay saldo acumulado encadenado entre días.
 * - diferencia (arqueo) = efectivo_contado − efectivo_esperado; faltante_meta = meta − total.
 */
class CajaService
{
    private const SIN_CUENTA = 'sin_cuenta';

    /* ───────────────────────── Configuración ───────────────────────── */

    public function metaVentaDiaria(): string
    {
        $valor = Configuracion::where('clave', Configuracion::META_VENTA_DIARIA)->value('valor');

        return self::dinero($valor ?? config('caja.meta_venta_diaria'));
    }

    /** Solo admin (ruta). Auditable (RF-11) guarda el valor anterior. */
    public function fijarMetaVentaDiaria(int $valor): string
    {
        Configuracion::firstOrCreate(
            ['clave' => Configuracion::META_VENTA_DIARIA],
            ['valor' => (string) config('caja.meta_venta_diaria')],
        )->update(['valor' => (string) $valor]);

        return self::dinero($valor);
    }

    /* ──────────────── Apertura automática (S-84; antes diaria, S-49) ──────────────── */

    /**
     * S-84: apertura efectiva de un día, calculada en vivo (no se escriben filas automáticas):
     *  a. con cierre vigente: la que se guardó en ese cierre (origen `cierre`);
     *  b. si no, el cambio manual registrado para ese día (origen `manual`, con su motivo);
     *  c. si no, el efectivo contado del último cierre vigente anterior (origen `arrastre`);
     *  d. si no hay nada (primer día sin cierres): null; el día no se cierra hasta registrarla.
     * El arrastre se recalcula solo: si el admin reabre y vuelve a cerrar el día anterior con otro
     * contado, cambia la apertura de un día aún sin cerrar; un día cerrado conserva la guardada.
     *
     * @return array{valor: string, origen: string, desde_cierre: ?string, cierre_anterior_valor: ?string, motivo: ?string, fijado_por: ?array{id: int, name: string}, fijado_at: ?string, hay_dias_sin_cerrar: bool}|null
     */
    public function aperturaDelDia(string $fecha): ?array
    {
        $anterior = $this->ultimoCierreVigente($fecha);
        $arrastre = $anterior ? self::dinero($anterior->efectivo_contado) : null;
        $cierre = $this->cierreVigente($fecha);
        $manual = AperturaCaja::query()->where('fecha', $fecha)->with('usuario:id,name')->first();
        // Una apertura registrada antes de S-84 (sin motivo) igual a la del cierre anterior no es un
        // cambio: se muestra como arrastre.
        $esCambio = $manual !== null && ! self::igualAlArrastre($manual, $arrastre);

        if ($cierre) {
            if ($cierre->apertura === null) {
                return null; // cierre anterior a la apertura de caja (RF-25 viejo): no tiene apertura
            }
            $valor = self::dinero($cierre->apertura);
            $origen = 'cierre';
        } elseif ($manual) {
            $valor = self::dinero($manual->valor);
            $origen = $esCambio ? 'manual' : 'arrastre';
        } elseif ($arrastre !== null) {
            $valor = $arrastre;
            $origen = 'arrastre';
        } else {
            return null;
        }

        return [
            'valor' => $valor,
            'origen' => $origen,
            // Con cierre: de dónde vino la apertura que se usó (manual si hubo cambio, si no el arrastre).
            'origen_usado' => $origen === 'cierre' ? ($esCambio ? 'manual' : 'arrastre') : $origen,
            'desde_cierre' => $anterior?->fecha->toDateString(),
            'cierre_anterior_valor' => $arrastre,
            'motivo' => $manual?->motivo,
            'fijado_por' => $manual?->usuario ? ['id' => $manual->usuario->id, 'name' => $manual->usuario->name] : null,
            'fijado_at' => $manual?->updated_at?->toIso8601String(),
            'hay_dias_sin_cerrar' => $cierre === null && $anterior !== null && $this->hayDiasSinCerrar($anterior->fecha->toDateString(), $fecha),
        ];
    }

    /** S-84: fila de apertura registrada antes de S-84 (sin motivo) con el mismo valor que el arrastre. */
    private static function igualAlArrastre(AperturaCaja $manual, ?string $arrastre): bool
    {
        return $manual->motivo === null && $arrastre !== null && bccomp(self::dinero($manual->valor), $arrastre, 2) === 0;
    }

    /**
     * S-84: entre el último cierre vigente y la fecha quedó algún día con movimientos sin cerrar (un
     * día sin actividad, como un domingo, no cuenta). Una sola consulta agregada sobre el intervalo;
     * con más de 31 días de diferencia se avisa sin calcular.
     */
    private function hayDiasSinCerrar(string $ultimoCierre, string $fecha): bool
    {
        $desde = Carbon::parse($ultimoCierre)->addDay()->toDateString();
        $hasta = Carbon::parse($fecha)->subDay()->toDateString();
        if ($desde > $hasta) {
            return false;
        }
        if (Carbon::parse($desde)->diffInDays(Carbon::parse($hasta)) > 31) {
            return true;
        }
        $cuentas = $this->cuentas();
        foreach ($this->agregados($desde, $hasta, $cuentas) as $dia => $agregado) {
            $r = $this->componer($dia, $agregado, $cuentas, '0');
            if (bccomp($r['total_ingreso_dia'], '0', 2) !== 0 || bccomp($r['efectivo_neto_dia'], '0', 2) !== 0 || bccomp($r['egresos']['total'], '0', 2) !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * S-84 ("Cambiar apertura"; antes S-49): el mostrador cambia a mano el efectivo con que abre la
     * caja de un día (hoy o antes), solo mientras ese día no tenga cierre vigente. Valor >= 0 con
     * máximo 2 decimales. Motivo obligatorio (3 a 200) cuando difiere de la apertura del cierre
     * anterior; en el primer día sin cierres, opcional. Igual al arrastre y sin cambio manual previo:
     * no crea fila (la apertura ya es esa). Auditable deja la creación y cada cambio (valor y motivo).
     *
     * @return array<string, mixed>|null la apertura efectiva resultante
     */
    public function fijarApertura(string $fecha, string|int|float $valor, ?string $motivo, User $usuario): ?array
    {
        $valor = (string) $valor;
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $valor)) {
            throw new ReglaInventarioException('La apertura debe ser un valor en pesos, 0 o mayor, con máximo 2 decimales.', 422, ['campo' => 'valor']);
        }
        if ($fecha > RangoFechas::hoy()) {
            throw new ReglaInventarioException('No se puede abrir la caja de un día futuro.', 422, ['campo' => 'fecha']);
        }
        $motivo = trim((string) $motivo);

        DB::transaction(function () use ($fecha, $valor, $motivo, $usuario) {
            $this->bloquearCaja();

            if ($this->cierreVigente($fecha)) {
                throw new ReglaInventarioException('El día ya está cerrado: no se puede cambiar la apertura.', 422, ['campo' => 'fecha']);
            }

            $anterior = $this->ultimoCierreVigente($fecha);
            $arrastre = $anterior ? self::dinero($anterior->efectivo_contado) : null;
            $valor = self::dinero($valor);
            $manual = AperturaCaja::query()->where('fecha', $fecha)->lockForUpdate()->first();
            $igualAlArrastre = $arrastre !== null && bccomp($valor, $arrastre, 2) === 0;

            // Igual a la del cierre anterior y sin cambio manual: la apertura ya es esa; nada que guardar.
            if ($igualAlArrastre && ! $manual) {
                return;
            }
            if ($arrastre !== null && ! $igualAlArrastre && mb_strlen($motivo) < 3) {
                throw new ReglaInventarioException('Escriba el motivo del cambio (difiere de lo que dejó el cierre anterior).', 422, ['campo' => 'motivo']);
            }
            if (mb_strlen($motivo) > 200) {
                throw new ReglaInventarioException('El motivo admite máximo 200 caracteres.', 422, ['campo' => 'motivo']);
            }

            $manual ??= new AperturaCaja(['fecha' => $fecha]);
            $manual->valor = $valor;
            $manual->motivo = $motivo !== '' ? $motivo : ($igualAlArrastre ? 'Vuelve a la apertura del cierre anterior' : null);
            $manual->usuario_id = $usuario->id;
            if (! $manual->exists || $manual->isDirty(['valor', 'motivo'])) {
                $manual->save();
            }
        });

        return $this->aperturaDelDia($fecha);
    }

    /* ───────────────────────── Día cerrado (S-53) ───────────────────────── */

    public function cierreVigente(string $fecha): ?CierreCaja
    {
        return CierreCaja::vigentes()->where('fecha', $fecha)->with('usuario:id,name')->orderByDesc('id')->first();
    }

    public function ultimoCierreVigente(?string $antesDe = null): ?CierreCaja
    {
        return CierreCaja::vigentes()
            ->when($antesDe, fn ($q) => $q->where('fecha', '<', $antesDe))
            ->orderByDesc('fecha')->orderByDesc('id')
            ->first();
    }

    /**
     * S-53: lo que mueve dinero HOY (cerrar una venta, una devolución de contado, un abono, un pago o
     * su anulación) no entra si la caja de hoy ya está cerrada: cambiaría un día ya cuadrado. Se llama
     * dentro de la transacción del caso de uso; el bloqueo compartido espera a un cierre en curso.
     */
    public function exigirDiaAbierto(string $accion): void
    {
        DB::table('secuencias')->where('nombre', 'caja')->sharedLock()->first();
        $hoy = RangoFechas::hoy();

        if (CierreCaja::vigentes()->where('fecha', $hoy)->exists()) {
            throw new ReglaInventarioException(
                "La caja de hoy ({$hoy}) ya está cerrada: no se puede {$accion}. Pídale al administrador que la reabra.",
                422,
                ['caja_cerrada' => $hoy],
            );
        }
    }

    /** Bloqueo exclusivo de la caja: cierres, reaperturas, movimientos y aperturas se serializan. */
    public function bloquearCaja(): void
    {
        DB::table('secuencias')->insertOrIgnore(['nombre' => 'caja', 'valor' => 0]);
        DB::table('secuencias')->where('nombre', 'caja')->lockForUpdate()->first();
    }

    /* ───────────────────────── Resumen del día (RF-25) ───────────────────────── */

    /**
     * Resumen calculado en vivo de un día: por cuenta, totales, efectivo neto, apertura efectiva
     * (S-84: del cierre, manual o arrastre del último cierre), efectivo esperado (null solo sin
     * apertura efectiva, el primer día: sin ella el día no se cierra) y meta.
     *
     * @return array<string, mixed>
     */
    public function resumenDia(string $fecha, ?Rol $para = null): array
    {
        $resumen = $this->calcularResumenDia($fecha);

        // S-88: el mostrador no recibe dueños ni saldos de billeteras.
        return $para === Rol::Mostrador ? $this->sinDuenosNiSaldos($resumen) : $resumen;
    }

    /** @return array<string, mixed> */
    private function calcularResumenDia(string $fecha): array
    {
        $cuentas = $this->cuentas();
        $agregados = $this->agregados($fecha, $fecha, $cuentas);
        $apertura = $this->aperturaDelDia($fecha);
        $r = $this->conSaldosBilletera(
            $this->componer($fecha, $agregados[$fecha] ?? $this->vacio(), $cuentas, $this->metaVentaDiaria()),
            $cuentas,
            $this->saldosBilleteraAntesDe($fecha, $cuentas),
        );

        // S-75: totales de la pestaña Movimientos, de los mismos agregados que el efectivo esperado
        // (sin anulados). Gastos = vario + fijo + financiero (= egresos.total); ingresos = otros
        // ingresos + ingresos externos, de todas las cuentas. Ventas, recaudo, pagos a proveedores y
        // préstamos a empleados no son movimientos de caja y no entran.
        $delDia = $agregados[$fecha] ?? $this->vacio();
        $conteos = $delDia['conteos'];
        $cantidad = fn (array $grupos) => array_sum(array_map(fn (GrupoCaja $g) => $conteos[$g->value], $grupos));

        return $r + [
            'totales_movimientos' => [
                'gastos' => [
                    'cantidad' => $cantidad([GrupoCaja::Vario, GrupoCaja::Fijo, GrupoCaja::Financiero]),
                    'total' => $r['egresos']['total'],
                ],
                'ingresos' => [
                    'cantidad' => $cantidad([GrupoCaja::OtroIngreso, GrupoCaja::IngresoExterno]),
                    'total' => bcadd($r['otros_ingresos'], $r['ingresos_externos'], 2),
                ],
                // S-76: recaudos de documentos (también dentro de recaudo_cartera); ni gasto ni ingreso.
                'recaudos' => [
                    'cantidad' => $delDia['conteo_recaudos'],
                    'total' => $delDia['recaudos_documento'],
                ],
                // RF-27: préstamos y abonos de empleados del día; ni gasto ni ingreso. Mismos agregados
                // que el detalle del efectivo: préstamos = "Préstamos a empleados" (siempre de Efectivo);
                // abonos por caja de todas las cuentas (la parte en Efectivo es "Abonos de empleados en
                // efectivo"); abonos por nómina aparte (no mueven la caja).
                'empleados' => [
                    'prestamos' => ['cantidad' => $delDia['conteo_empleados']['prestamos'], 'total' => $r['prestamos_empleados']],
                    'abonos_caja' => ['cantidad' => $delDia['conteo_empleados']['abonos_caja'], 'total' => $r['abonos_empleados']],
                    'abonos_nomina' => ['cantidad' => $delDia['conteo_empleados']['abonos_nomina'], 'total' => $delDia['abonos_nomina']],
                ],
            ],
            'apertura' => $apertura,
            'apertura_editable' => $fecha <= RangoFechas::hoy() && $this->cierreVigente($fecha) === null,
            'efectivo_esperado' => $apertura ? bcadd($apertura['valor'], $r['efectivo_neto_dia'], 2) : null,
        ];
    }

    /* ───────────────────────── Cuadre mensual (RF-25) ───────────────────────── */

    /**
     * Una fila por día del mes (hasta hoy) al estilo de la planilla, con fila de totales. Los días con
     * cierre vigente usan lo guardado (apertura, efectivo esperado y contado, diferencia, observación);
     * los demás se calculan en vivo y se marcan "sin cerrar". `neto_acumulado_mes` es informativo: la
     * suma del efectivo neto desde el primer día del mes, empezando en 0. No hay saldo encadenado.
     *
     * @return array<string, mixed>
     */
    public function resumenMes(string $mes): array
    {
        $primero = Carbon::createFromFormat('Y-m-d', $mes.'-01')->startOfDay();
        $ultimo = $primero->copy()->endOfMonth();
        $hasta = min($ultimo->toDateString(), RangoFechas::hoy());
        $desde = $primero->toDateString();

        $cuentas = $this->cuentas();
        $meta = $this->metaVentaDiaria();
        $filas = [];
        $acumulado = '0.00';
        $billeteras = [];

        if ($desde <= $hasta) {
            $agregados = $this->agregados($desde, $hasta, $cuentas);
            // S-88: el saldo al empezar el mes es el neto acumulado de las billeteras hasta el día anterior.
            $saldoBilletera = $this->saldosBilleteraAntesDe($desde, $cuentas);
            $netoMes = array_fill_keys(array_keys($saldoBilletera), '0.00');
            $cierres = CierreCaja::vigentes()->whereBetween('fecha', [$desde, $hasta])->get()->keyBy(fn (CierreCaja $c) => $c->fecha->toDateString());
            $filasApertura = AperturaCaja::query()->whereBetween('fecha', [$desde, $hasta])->get(['fecha', 'valor', 'motivo'])
                ->keyBy(fn (AperturaCaja $a) => $a->fecha->toDateString());
            $aperturas = $filasApertura->map(fn (AperturaCaja $a) => self::dinero($a->valor));
            // S-84: el arrastre de cada día sale del último cierre vigente anterior; se lleva en memoria
            // con los cierres del mes y el último anterior al mes (una consulta por el índice de fecha).
            $previo = $this->ultimoCierreVigente($desde);
            $arrastre = $previo ? self::dinero($previo->efectivo_contado) : null;

            for ($d = $primero->copy(); $d->toDateString() <= $hasta; $d->addDay()) {
                $dia = $d->toDateString();
                $r = $this->componer($dia, $agregados[$dia] ?? $this->vacio(), $cuentas, $meta);
                $cierre = $cierres[$dia] ?? null;
                $acumulado = bcadd($acumulado, $r['efectivo_neto_dia'], 2);

                // Cerrado: lo guardado (un cierre anterior a la apertura diaria no tiene apertura).
                // Sin cerrar (S-84): el cambio manual si lo hay; si no, el arrastre del último cierre.
                // El origen dice de dónde vino: manual si hubo cambio ese día, si no arrastre.
                if ($cierre) {
                    $apertura = $cierre->apertura !== null ? self::dinero($cierre->apertura) : null;
                    $esperado = self::dinero($cierre->efectivo_esperado);
                    $cambio = isset($filasApertura[$dia]) && ! self::igualAlArrastre($filasApertura[$dia], $arrastre);
                    $origen = $apertura === null ? null : ($cambio ? 'manual' : 'arrastre');
                    $arrastre = self::dinero($cierre->efectivo_contado);
                } else {
                    $apertura = $aperturas[$dia] ?? $arrastre;
                    $cambio = isset($filasApertura[$dia]) && ! self::igualAlArrastre($filasApertura[$dia], $arrastre);
                    $origen = $cambio ? 'manual' : ($apertura !== null ? 'arrastre' : null);
                    $esperado = $apertura !== null ? bcadd($apertura, $r['efectivo_neto_dia'], 2) : null;
                }

                $porTipo = array_fill_keys(TipoCuentaPago::valores(), '0.00');
                foreach ($r['cuentas'] as $c) {
                    $tipo = $c['tipo'] ?? TipoCuentaPago::Efectivo->value;
                    $porTipo[$tipo] = bcadd($porTipo[$tipo], $c['ventas'], 2);
                }

                foreach ($r['cuentas'] as $c) {
                    if (($c['tipo'] ?? null) !== TipoCuentaPago::Billetera->value || $c['cuenta_pago_id'] === null) {
                        continue;
                    }
                    $id = $c['cuenta_pago_id'];
                    $netoMes[$id] = bcadd($netoMes[$id] ?? '0.00', $c['neto'], 2);
                    $saldoBilletera[$id] = bcadd($saldoBilletera[$id] ?? '0.00', $c['neto'], 2);
                }

                $filas[] = [
                    'fecha' => $dia,
                    'apertura' => $apertura,
                    'apertura_origen' => $origen, // S-84: 'arrastre' | 'manual' | null
                    'ventas_efectivo' => $porTipo['efectivo'],
                    'ventas_banco' => $porTipo['banco'],
                    'ventas_datafono' => $porTipo['datafono'],
                    'ventas_billetera' => $porTipo['billetera'],
                    'ventas_credito' => $r['ventas_credito'],
                    'otros_ingresos' => $r['otros_ingresos'],
                    'total_ingreso_dia' => $r['total_ingreso_dia'],
                    'egresos' => $r['egresos']['total'],
                    'efectivo_neto_dia' => $r['efectivo_neto_dia'],
                    'efectivo_esperado' => $esperado,
                    'efectivo_contado' => $cierre ? self::dinero($cierre->efectivo_contado) : null,
                    'diferencia' => $cierre ? self::dinero($cierre->diferencia) : null,
                    'neto_acumulado_mes' => $acumulado,
                    'meta' => $meta,
                    'faltante_meta' => $r['faltante_meta'],
                    'observacion' => $cierre?->observacion,
                    'cerrado' => $cierre !== null,
                ];
            }
            $anteriorMes = [];
            foreach ($saldoBilletera as $id => $fin) {
                $anteriorMes[$id] = bcsub($fin, $netoMes[$id] ?? '0.00', 2);
            }
            $billeteras = $this->agruparBilleteras($cuentas, $netoMes, $saldoBilletera, $anteriorMes);
        }

        $sumar = fn (string $col) => array_reduce($filas, fn ($t, $f) => $f[$col] === null ? $t : bcadd($t, $f[$col], 2), '0.00');
        $columnas = ['ventas_efectivo', 'ventas_banco', 'ventas_datafono', 'ventas_billetera', 'ventas_credito', 'otros_ingresos',
            'total_ingreso_dia', 'egresos', 'efectivo_neto_dia', 'meta', 'faltante_meta', 'diferencia'];

        return [
            'mes' => $mes,
            'meta_venta_diaria' => $meta,
            'dias' => $filas,
            'billeteras' => $billeteras,
            'totales' => array_combine($columnas, array_map($sumar, $columnas)) + [
                'neto_acumulado_mes' => $acumulado,
                'dias_cerrados' => count(array_filter($filas, fn ($f) => $f['cerrado'])),
                'dias_sin_cerrar' => count(array_filter($filas, fn ($f) => ! $f['cerrado'])),
            ],
        ];
    }

    /* ───────────────────────── Cálculo ───────────────────────── */

    /** Todas las cuentas (también inactivas: tienen historia), por id. */
    public function cuentas(): Collection
    {
        return CuentaPago::query()->with('propietario:id,nombre')->orderBy('id')->get(['id', 'nombre', 'tipo', 'activa', 'propietario_id'])->keyBy('id');
    }

    /**
     * Sumas por día y cuenta de cada origen, para [desde, hasta] (días de Bogotá). Siete consultas
     * agrupadas, sin importar cuántos días.
     *
     * @return array<string, array<string, mixed>>
     */
    public function agregados(string $desde, string $hasta, Collection $cuentas): array
    {
        $ini = RangoFechas::inicio($desde);
        $fin = RangoFechas::finExclusivo($hasta);
        $efectivoId = $cuentas->first(fn (CuentaPago $c) => $c->tipo === TipoCuentaPago::Efectivo)?->id;
        $out = [];
        $poner = function (string $dia, string $clave, $sub, string $valor) use (&$out) {
            $out[$dia] ??= $this->vacio();
            if ($sub === null) {
                $out[$dia][$clave] = bcadd($out[$dia][$clave], $valor, 2);
            } else {
                $out[$dia][$clave][$sub] = bcadd($out[$dia][$clave][$sub] ?? '0', $valor, 2);
            }
        };

        // 1. Ventas de contado pagadas, por cuenta (S-48).
        $q = DB::table('venta_pagos as vp')
            ->join('ventas as v', 'v.id', '=', 'vp.venta_id')
            ->where('v.estado', 'cerrada')->where('v.cerrada_at', '>=', $ini)->where('v.cerrada_at', '<', $fin)
            ->selectRaw($this->dia('v.cerrada_at').' as dia, vp.cuenta_pago_id as cuenta, SUM(vp.valor) as valor')
            ->groupBy('dia', 'cuenta');
        foreach ($q->get() as $f) {
            $poner($f->dia, 'ventas', (int) $f->cuenta, (string) $f->valor);
        }

        // 2. Ventas al cierre (ítems no reversados antes del cierre): crédito y contado sin detalle.
        $q = DB::table('ventas as v')
            ->join('venta_items as vi', 'vi.venta_id', '=', 'v.id')
            ->leftJoin('reverso_items as ri', 'ri.venta_item_id', '=', 'vi.id')
            ->leftJoin('reversos as r', fn ($j) => $j->on('r.id', '=', 'ri.reverso_id')->on('r.created_at', '<', 'v.cerrada_at'))
            ->where('v.estado', 'cerrada')->where('v.cerrada_at', '>=', $ini)->where('v.cerrada_at', '<', $fin)
            ->whereNull('r.id')
            ->where(fn ($w) => $w->where('v.forma_pago', 'credito')
                ->orWhereNotExists(fn ($s) => $s->from('venta_pagos as vp')->whereColumn('vp.venta_id', 'v.id')->selectRaw('1')))
            ->selectRaw($this->dia('v.cerrada_at').' as dia, v.forma_pago, COALESCE(SUM(vi.precio_unitario), 0) as valor')
            ->groupBy('dia', 'v.forma_pago');
        foreach ($q->get() as $f) {
            $poner($f->dia, $f->forma_pago === 'credito' ? 'ventas_credito' : 'ventas_sin_detalle', null, (string) $f->valor);
        }

        // 2b. S-73: ventas a crédito de solo valor (sin ítems): crédito del día de su registro, como las
        // demás a crédito. Cuentan para la meta y no entran a ninguna cuenta de pago; una anulación
        // posterior no resta del día (igual que el reverso de una venta a crédito ya cerrada).
        $q = DB::table('ventas as v')
            ->where('v.estado', 'cerrada')->where('v.cerrada_at', '>=', $ini)->where('v.cerrada_at', '<', $fin)
            ->where('v.forma_pago', 'credito')
            ->whereNotNull('v.valor_total')
            ->selectRaw($this->dia('v.cerrada_at').' as dia, SUM(v.valor_total) as valor')
            ->groupBy('dia');
        foreach ($q->get() as $f) {
            $poner($f->dia, 'ventas_credito', null, (string) $f->valor);
        }

        // 3. Devoluciones de ventas de contado ya cerradas (S-50): salen del efectivo.
        $q = DB::table('reversos as r')
            ->join('ventas as v', 'v.id', '=', 'r.venta_id')
            ->join('reverso_items as ri', 'ri.reverso_id', '=', 'r.id')
            ->join('venta_items as vi', 'vi.id', '=', 'ri.venta_item_id')
            ->where('r.created_at', '>=', $ini)->where('r.created_at', '<', $fin)
            ->where('v.forma_pago', 'contado')->where('v.estado', 'cerrada')
            ->whereColumn('r.created_at', '>=', 'v.cerrada_at')
            ->selectRaw($this->dia('r.created_at').' as dia, COALESCE(SUM(vi.precio_unitario), 0) as valor')
            ->groupBy('dia');
        foreach ($q->get() as $f) {
            $poner($f->dia, 'devoluciones', null, (string) $f->valor);
        }

        // 4 y 5. Recaudo de cartera y pagos a proveedores, por cuenta y día de registro; una anulación
        // resta el día en que se anula. Sin cuenta (registros viejos): Efectivo si su medio lo era.
        foreach ([['abonos_clientes', 'abono_anulaciones', 'abono_id', 'recaudo'], ['pagos_proveedores', 'pago_anulaciones', 'pago_id', 'pagos_proveedores']] as [$tabla, $anul, $fk, $clave]) {
            $altas = DB::table($tabla.' as m')
                ->where('m.created_at', '>=', $ini)->where('m.created_at', '<', $fin)
                ->selectRaw($this->dia('m.created_at').' as dia, m.cuenta_pago_id as cuenta, m.medio, SUM(m.valor) as valor')
                ->groupBy('dia', 'cuenta', 'm.medio');
            $bajas = DB::table($anul.' as x')
                ->join($tabla.' as m', 'm.id', '=', 'x.'.$fk)
                ->where('x.created_at', '>=', $ini)->where('x.created_at', '<', $fin)
                ->selectRaw($this->dia('x.created_at').' as dia, m.cuenta_pago_id as cuenta, m.medio, -SUM(m.valor) as valor')
                ->groupBy('dia', 'cuenta', 'm.medio');
            foreach ($altas->unionAll($bajas)->get() as $f) {
                $cuenta = $f->cuenta !== null ? (int) $f->cuenta : ($f->medio === 'efectivo' && $efectivoId ? $efectivoId : self::SIN_CUENTA);
                $poner($f->dia, $clave, $cuenta, (string) $f->valor);
            }
        }

        // 5b. S-76: recaudo de documentos de Contapyme (no son ventas a crédito del sistema), no anulados,
        // por su día de caja y cuenta. Van con el recaudo de cartera: suman al neto de su cuenta y, en
        // efectivo, al esperado; no a la meta. La anulación solo se permite con el día abierto, así que
        // basta excluir los anulados de su propio día. El conteo sale de la misma consulta.
        $q = DB::table('recaudos_documento as r')
            ->leftJoin('recaudo_documento_anulaciones as x', 'x.recaudo_id', '=', 'r.id')
            ->whereBetween('r.fecha', [$desde, $hasta])->whereNull('x.id')
            ->selectRaw('r.fecha as dia, r.cuenta_pago_id as cuenta, SUM(r.valor) as valor, COUNT(*) as cantidad')
            ->groupBy('r.fecha', 'r.cuenta_pago_id');
        foreach ($q->get() as $f) {
            $dia = (string) $f->dia;
            $poner($dia, 'recaudo', (int) $f->cuenta, (string) $f->valor);
            $poner($dia, 'recaudos_documento', null, (string) $f->valor);
            $out[$dia]['conteo_recaudos'] += (int) $f->cantidad;
        }

        // 6. Movimientos de caja no anulados, por grupo y cuenta (RF-26). El conteo sale de la misma
        // consulta: totales de la pestaña Movimientos (S-75).
        $q = DB::table('movimientos_caja as m')
            ->leftJoin('movimiento_caja_anulaciones as x', 'x.movimiento_id', '=', 'm.id')
            ->whereBetween('m.fecha', [$desde, $hasta])->whereNull('x.id')
            ->selectRaw('m.fecha as dia, m.grupo, m.cuenta_pago_id as cuenta, SUM(m.valor) as valor, COUNT(*) as cantidad')
            ->groupBy('m.fecha', 'm.grupo', 'm.cuenta_pago_id');
        foreach ($q->get() as $f) {
            $out[$f->dia] ??= $this->vacio();
            $out[$f->dia]['movimientos'][$f->grupo][(int) $f->cuenta] = bcadd($out[$f->dia]['movimientos'][$f->grupo][(int) $f->cuenta] ?? '0', (string) $f->valor, 2);
            $out[$f->dia]['conteos'][$f->grupo] += (int) $f->cantidad;
        }

        // 7. Préstamos y abonos de empleados (RF-27, S-55, S-56): por su fecha (siempre la del registro)
        // y cuenta; una anulación resta el día en que se anula. Sin apertura. El abono por nómina no
        // mueve la caja: va aparte, solo para los totales de la pestaña Movimientos. El conteo sale de
        // la misma consulta (altas − anulaciones del día, como el valor).
        $altas = DB::table('movimientos_empleado as m')
            ->whereBetween('m.fecha', [$desde, $hasta])->where('m.apertura', false)
            ->selectRaw('m.fecha as dia, m.tipo, m.forma, m.cuenta_pago_id as cuenta, SUM(m.valor) as valor, COUNT(*) as cantidad')
            ->groupBy('m.fecha', 'm.tipo', 'm.forma', 'm.cuenta_pago_id');
        $bajas = DB::table('movimiento_empleado_anulaciones as x')
            ->join('movimientos_empleado as m', 'm.id', '=', 'x.movimiento_id')
            ->where('x.created_at', '>=', $ini)->where('x.created_at', '<', $fin)->where('m.apertura', false)
            ->selectRaw($this->dia('x.created_at').' as dia, m.tipo, m.forma, m.cuenta_pago_id as cuenta, -SUM(m.valor) as valor, -COUNT(*) as cantidad')
            ->groupBy('dia', 'm.tipo', 'm.forma', 'cuenta');
        foreach ($altas->unionAll($bajas)->get() as $f) {
            $dia = (string) $f->dia;
            $clave = $f->tipo === 'prestamo' ? 'prestamos' : ($f->forma === 'nomina' ? 'abonos_nomina' : 'abonos_caja');
            if ($clave === 'abonos_nomina') {
                $poner($dia, 'abonos_nomina', null, (string) $f->valor);
            } else {
                $cuenta = $f->cuenta !== null ? (int) $f->cuenta : ($efectivoId ?? self::SIN_CUENTA);
                $poner($dia, $clave === 'prestamos' ? 'prestamos_empleados' : 'abonos_empleados', $cuenta, (string) $f->valor);
            }
            $out[$dia]['conteo_empleados'][$clave] += (int) $f->cantidad;
        }

        return $out;
    }

    /**
     * S-88: saldo de cada billetera al empezar $fecha (neto acumulado de los días anteriores).
     * Se calcula; no se guarda. El efectivo del cajón no entra aquí (S-49).
     *
     * @return array<int, string> cuenta_pago_id => saldo
     */
    private function saldosBilleteraAntesDe(string $fecha, Collection $cuentas): array
    {
        $ids = $cuentas->filter(fn (CuentaPago $c) => $c->tipo === TipoCuentaPago::Billetera)->keys()->all();
        $saldos = array_fill_keys($ids, '0.00');
        $hasta = Carbon::parse($fecha)->subDay()->toDateString();
        if ($ids === [] || $hasta < '2000-01-01') {
            return $saldos;
        }

        foreach ($this->agregados('2000-01-01', $hasta, $cuentas) as $a) {
            foreach ($ids as $id) {
                $saldos[$id] = bcadd($saldos[$id], $this->netoBilletera((int) $id, $a), 2);
            }
        }

        return $saldos;
    }

    /**
     * Neto del día de una billetera. Es el mismo cálculo de `componer` para una cuenta que no es
     * efectivo: las devoluciones y las ventas sin detalle de pago solo tocan el efectivo.
     *
     * @param  array<string, mixed>  $a
     */
    private function netoBilletera(int $id, array $a): string
    {
        $mov = $a['movimientos'];
        $entra = '0.00';
        foreach ([
            $a['ventas'][$id] ?? '0',
            $a['recaudo'][$id] ?? '0',
            $mov['otro_ingreso'][$id] ?? '0',
            $mov['ingreso_externo'][$id] ?? '0',
            $a['abonos_empleados'][$id] ?? '0',
        ] as $valor) {
            $entra = bcadd($entra, (string) $valor, 2);
        }
        $sale = '0.00';
        foreach (['vario', 'fijo', 'financiero'] as $grupo) {
            $sale = bcadd($sale, (string) ($mov[$grupo][$id] ?? '0'), 2);
        }
        foreach ([$a['pagos_proveedores'][$id] ?? '0', $a['prestamos_empleados'][$id] ?? '0'] as $valor) {
            $sale = bcadd($sale, (string) $valor, 2);
        }

        return bcsub($entra, $sale, 2);
    }

    /**
     * @param  array<string, mixed>  $resumen
     * @param  array<int, string>  $anteriores
     * @return array<string, mixed>
     */
    private function conSaldosBilletera(array $resumen, Collection $cuentas, array $anteriores): array
    {
        $neto = [];
        $saldo = $anteriores;
        foreach ($resumen['cuentas'] as $i => $fila) {
            if (($fila['tipo'] ?? null) !== TipoCuentaPago::Billetera->value || $fila['cuenta_pago_id'] === null) {
                continue;
            }
            $id = $fila['cuenta_pago_id'];
            $ant = $anteriores[$id] ?? '0.00';
            $fin = bcadd($ant, $fila['neto'], 2);
            $resumen['cuentas'][$i]['saldo_anterior'] = $ant;
            $resumen['cuentas'][$i]['saldo'] = $fin;
            $neto[$id] = $fila['neto'];
            $saldo[$id] = $fin;
        }
        $resumen['billeteras'] = $this->agruparBilleteras($cuentas, $neto, $saldo, $anteriores);

        return $resumen;
    }

    /**
     * S-88: el mostrador no ve de quién es cada billetera ni su saldo: se quitan `billeteras` y, en cada
     * cuenta, `propietario_id`, `propietario`, `saldo_anterior` y `saldo`. Admin y contable los reciben.
     *
     * @param  array<string, mixed>  $resumen
     * @return array<string, mixed>
     */
    public function sinDuenosNiSaldos(array $resumen): array
    {
        unset($resumen['billeteras']);
        $resumen['cuentas'] = array_map(
            fn (array $fila) => array_diff_key($fila, array_flip(['propietario_id', 'propietario', 'saldo_anterior', 'saldo'])),
            $resumen['cuentas'] ?? [],
        );

        return $resumen;
    }

    /**
     * @param  array<int, string>  $neto
     * @param  array<int, string>  $saldo
     * @param  array<int, string>  $anterior
     * @return list<array<string, mixed>>
     */
    private function agruparBilleteras(Collection $cuentas, array $neto, array $saldo, array $anterior): array
    {
        $grupos = [];
        foreach ($cuentas as $c) {
            if ($c->tipo !== TipoCuentaPago::Billetera) {
                continue;
            }
            $id = (int) $c->id;
            $n = $neto[$id] ?? '0.00';
            $s = $saldo[$id] ?? ($anterior[$id] ?? '0.00');
            $a = $anterior[$id] ?? '0.00';
            if (! $c->activa && bccomp($n, '0', 2) === 0 && bccomp($s, '0', 2) === 0) {
                continue;
            }
            $clave = $c->propietario_id ?? 0;
            $grupos[$clave] ??= [
                'propietario_id' => $c->propietario_id,
                'propietario' => $c->propietario?->nombre ?? 'Sin dueño',
                'neto' => '0.00',
                'saldo_anterior' => '0.00',
                'saldo' => '0.00',
                'cuentas' => [],
            ];
            $grupos[$clave]['neto'] = bcadd($grupos[$clave]['neto'], $n, 2);
            $grupos[$clave]['saldo_anterior'] = bcadd($grupos[$clave]['saldo_anterior'], $a, 2);
            $grupos[$clave]['saldo'] = bcadd($grupos[$clave]['saldo'], $s, 2);
            $grupos[$clave]['cuentas'][] = [
                'cuenta_pago_id' => $id,
                'nombre' => $c->nombre,
                'neto' => $n,
                'saldo_anterior' => $a,
                'saldo' => $s,
            ];
        }
        foreach ($grupos as &$grupo) {
            usort($grupo['cuentas'], fn ($x, $y) => $x['nombre'] <=> $y['nombre']);
        }
        unset($grupo);
        $lista = array_values($grupos);
        usort($lista, function ($x, $y) {
            if ($x['propietario_id'] === null) {
                return 1;
            }
            if ($y['propietario_id'] === null) {
                return -1;
            }

            return strcasecmp($x['propietario'], $y['propietario']);
        });

        return $lista;
    }

    /** @return array<string, mixed> */
    private function vacio(): array
    {
        return [
            'ventas' => [],
            'ventas_sin_detalle' => '0.00',
            'ventas_credito' => '0.00',
            'devoluciones' => '0.00',
            'recaudo' => [],
            'pagos_proveedores' => [],
            'prestamos_empleados' => [],
            'abonos_empleados' => [],
            'movimientos' => array_fill_keys(GrupoCaja::valores(), []),
            'conteos' => array_fill_keys(GrupoCaja::valores(), 0),
            'recaudos_documento' => '0.00', // S-76: ya incluidos en 'recaudo'; aparte solo para los totales
            'conteo_recaudos' => 0,
            // RF-27: abonos de empleados por nómina (no mueven la caja) y conteos, solo para los totales.
            'abonos_nomina' => '0.00',
            'conteo_empleados' => ['prestamos' => 0, 'abonos_caja' => 0, 'abonos_nomina' => 0],
        ];
    }

    /**
     * Arma el resumen de un día (S-49 a S-51).
     *
     * @return array<string, mixed>
     */
    private function componer(string $fecha, array $a, Collection $cuentas, string $meta): array
    {
        $esEfectivo = fn ($id) => $id !== self::SIN_CUENTA && ($cuentas[$id] ?? null)?->tipo === TipoCuentaPago::Efectivo;
        $sumarSi = fn (array $porCuenta, callable $cond) => array_reduce(array_keys($porCuenta), fn ($t, $id) => $cond($id) ? bcadd($t, $porCuenta[$id], 2) : $t, '0.00');
        $todo = fn () => true;
        $efectivoId = $cuentas->first(fn (CuentaPago $c) => $c->tipo === TipoCuentaPago::Efectivo)?->id;

        $mov = $a['movimientos'];
        $egresosPorCuenta = [];
        foreach ([GrupoCaja::Vario, GrupoCaja::Fijo, GrupoCaja::Financiero] as $g) {
            foreach ($mov[$g->value] as $id => $v) {
                $egresosPorCuenta[$id] = bcadd($egresosPorCuenta[$id] ?? '0', $v, 2);
            }
        }
        $egresos = [
            'vario' => $sumarSi($mov['vario'], $todo),
            'fijo' => $sumarSi($mov['fijo'], $todo),
            'financiero' => $sumarSi($mov['financiero'], $todo),
        ];
        $egresos['total'] = bcadd(bcadd($egresos['vario'], $egresos['fijo'], 2), $egresos['financiero'], 2);

        $ventasContado = bcadd($sumarSi($a['ventas'], $todo), $a['ventas_sin_detalle'], 2);
        $otrosIngresos = $sumarSi($mov['otro_ingreso'], $todo);
        $ingresosExternos = $sumarSi($mov['ingreso_externo'], $todo);
        $recaudo = $sumarSi($a['recaudo'], $todo);
        $pagos = $sumarSi($a['pagos_proveedores'], $todo);
        $prestamosEmpleados = $sumarSi($a['prestamos_empleados'], $todo);
        $abonosEmpleados = $sumarSi($a['abonos_empleados'], $todo);

        $efectivo = [
            'ventas' => bcadd($sumarSi($a['ventas'], $esEfectivo), $a['ventas_sin_detalle'], 2),
            'recaudo' => $sumarSi($a['recaudo'], $esEfectivo),
            'otros_ingresos' => $sumarSi($mov['otro_ingreso'], $esEfectivo),
            'ingresos_externos' => $ingresosExternos,
            'devoluciones' => $a['devoluciones'],
            'pagos_proveedores' => $sumarSi($a['pagos_proveedores'], $esEfectivo),
            'egresos' => $egresos['total'],
            'abonos_empleados' => $sumarSi($a['abonos_empleados'], $esEfectivo),
            'prestamos_empleados' => $sumarSi($a['prestamos_empleados'], $esEfectivo),
        ];
        $neto = '0.00';
        foreach (['ventas', 'recaudo', 'otros_ingresos', 'ingresos_externos', 'abonos_empleados'] as $k) {
            $neto = bcadd($neto, $efectivo[$k], 2);
        }
        foreach (['devoluciones', 'pagos_proveedores', 'egresos', 'prestamos_empleados'] as $k) {
            $neto = bcsub($neto, $efectivo[$k], 2);
        }
        $efectivo['neto'] = $neto;

        // Filas por cuenta: las activas siempre; las inactivas y "sin cuenta" solo si tienen valores.
        $ids = array_unique(array_merge(
            $cuentas->filter(fn ($c) => $c->activa)->keys()->all(),
            array_keys($a['ventas']), array_keys($a['recaudo']), array_keys($a['pagos_proveedores']),
            array_keys($mov['otro_ingreso']), array_keys($mov['ingreso_externo']), array_keys($egresosPorCuenta),
            array_keys($a['prestamos_empleados']), array_keys($a['abonos_empleados']),
        ), SORT_REGULAR);
        $filas = [];
        foreach ($ids as $id) {
            $c = $id === self::SIN_CUENTA ? null : ($cuentas[$id] ?? null);
            $fila = [
                'cuenta_pago_id' => $id === self::SIN_CUENTA ? null : (int) $id,
                'nombre' => $c?->nombre ?? 'Sin cuenta (registros anteriores)',
                'tipo' => $c?->tipo->value,
                'activa' => $c?->activa ?? false,
                'propietario_id' => $c?->propietario_id,
                'propietario' => $c?->propietario?->nombre,
                'ventas' => self::dinero($a['ventas'][$id] ?? '0'),
                'recaudo' => self::dinero($a['recaudo'][$id] ?? '0'),
                'pagos_proveedores' => self::dinero($a['pagos_proveedores'][$id] ?? '0'),
                'otros_ingresos' => self::dinero($mov['otro_ingreso'][$id] ?? '0'),
                'ingresos_externos' => self::dinero($mov['ingreso_externo'][$id] ?? '0'),
                'egresos' => self::dinero($egresosPorCuenta[$id] ?? '0'),
                'devoluciones' => '0.00',
                'abonos_empleados' => self::dinero($a['abonos_empleados'][$id] ?? '0'),
                'prestamos_empleados' => self::dinero($a['prestamos_empleados'][$id] ?? '0'),
            ];
            if ($id === $efectivoId) {
                $fila['ventas'] = bcadd($fila['ventas'], $a['ventas_sin_detalle'], 2);
                $fila['ventas_sin_detalle'] = $a['ventas_sin_detalle'];
                $fila['devoluciones'] = $a['devoluciones'];
            }
            $fila['neto'] = bcsub(bcsub(bcsub(
                bcadd(bcadd(bcadd($fila['ventas'], $fila['recaudo'], 2), $fila['otros_ingresos'], 2), $fila['ingresos_externos'], 2),
                $fila['pagos_proveedores'], 2), $fila['egresos'], 2), $fila['devoluciones'], 2);
            $fila['neto'] = bcsub(bcadd($fila['neto'], $fila['abonos_empleados'], 2), $fila['prestamos_empleados'], 2);
            $filas[] = $fila;
        }
        usort($filas, fn ($x, $y) => [$x['cuenta_pago_id'] === null, $x['cuenta_pago_id']] <=> [$y['cuenta_pago_id'] === null, $y['cuenta_pago_id']]);

        $total = bcadd(bcadd($ventasContado, $a['ventas_credito'], 2), $otrosIngresos, 2);

        return [
            'fecha' => $fecha,
            'cuentas' => $filas,
            'ventas_contado' => $ventasContado,
            'ventas_sin_detalle' => $a['ventas_sin_detalle'],
            'ventas_credito' => $a['ventas_credito'],
            'devoluciones' => $a['devoluciones'],
            'recaudo_cartera' => $recaudo,
            'pagos_proveedores' => $pagos,
            'otros_ingresos' => $otrosIngresos,
            'ingresos_externos' => $ingresosExternos,
            'prestamos_empleados' => $prestamosEmpleados,
            'abonos_empleados' => $abonosEmpleados,
            'egresos' => $egresos,
            'efectivo' => $efectivo,
            'total_ingreso_dia' => $total,
            'efectivo_neto_dia' => $neto,
            'meta_venta_diaria' => $meta,
            'faltante_meta' => bcsub($meta, $total, 2),
        ];
    }

    /** Día local de una columna timestamp (UTC en la base) con desfase fijo, sin tablas de zonas. */
    private function dia(string $columna): string
    {
        $app = Carbon::now(config('app.timezone'))->format('P');
        $local = Carbon::now(config('app.zona_local', 'America/Bogota'))->format('P');

        return "DATE(CONVERT_TZ({$columna}, '{$app}', '{$local}'))";
    }

    public static function dinero(string|int|float|null $valor): string
    {
        return bcadd(is_string($valor) ? ($valor === '' ? '0' : $valor) : number_format((float) $valor, 2, '.', ''), '0', 2);
    }
}
