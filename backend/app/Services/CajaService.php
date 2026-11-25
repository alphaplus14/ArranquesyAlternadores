<?php

namespace App\Services;

use App\Enums\GrupoCaja;
use App\Enums\TipoCuentaPago;
use App\Exceptions\ReglaInventarioException;
use App\Models\CierreCaja;
use App\Models\Configuracion;
use App\Models\CuentaPago;
use App\Support\RangoFechas;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RF-25 (S-49 a S-51, S-53): cálculo de la caja diaria y del cuadre mensual, configuración (meta y
 * saldo inicial) y la regla "día cerrado". Solo lee y calcula; las escrituras de caja están en
 * MovimientoCajaService y CierreCajaService.
 *
 * Días en hora de Bogotá (RangoFechas). Cada origen es UNA consulta agrupada por día y cuenta, para
 * cualquier rango (un día o un mes): ventas pagadas, ventas al cierre (crédito y contado sin detalle),
 * devoluciones, recaudo de cartera, pagos a proveedores, movimientos de caja y préstamos y abonos de
 * empleados (RF-27).
 *
 * Fórmulas (S-49, S-51):
 * - total_ingreso_dia = ventas de contado + ventas a crédito + otros ingresos. El recaudo de cartera
 *   y los ingresos externos no cuentan para la meta.
 * - efectivo_neto_dia = ventas en efectivo + recaudo en efectivo + otros ingresos en efectivo +
 *   ingresos externos + abonos de empleados en efectivo − devoluciones de contado − pagos a
 *   proveedores en efectivo − egresos − préstamos a empleados (RF-27, S-55: ni la apertura ni el
 *   abono por nómina cuentan; no son venta ni cuentan para la meta).
 * - saldo_acumulado = saldo_anterior + efectivo_neto_dia.
 * - diferencia (arqueo) = efectivo_contado − efectivo_neto_dia; faltante_meta = meta − total.
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

    /** @return array{valor: string, fecha: string}|null */
    public function saldoInicial(): ?array
    {
        $filas = Configuracion::whereIn('clave', [Configuracion::SALDO_CAJA_INICIAL, Configuracion::SALDO_CAJA_INICIAL_FECHA])
            ->pluck('valor', 'clave');
        $valor = $filas[Configuracion::SALDO_CAJA_INICIAL] ?? null;
        $fecha = $filas[Configuracion::SALDO_CAJA_INICIAL_FECHA] ?? null;

        return $valor === null || $fecha === null ? null : ['valor' => self::dinero($valor), 'fecha' => $fecha];
    }

    /**
     * S-49: el saldo inicial y su fecha se fijan UNA vez (solo admin). Corregirlo después cambiaría
     * los saldos de todos los cierres ya guardados. Auditable deja la creación en la auditoría.
     *
     * @return array{valor: string, fecha: string}
     */
    public function fijarSaldoInicial(string|int $valor, string $fecha): array
    {
        return DB::transaction(function () use ($valor, $fecha) {
            $this->bloquearCaja();

            if ($this->saldoInicial() !== null) {
                throw new ReglaInventarioException('El saldo inicial de caja ya se fijó y no se puede cambiar.', 422);
            }
            if ($fecha > RangoFechas::hoy()) {
                throw new ReglaInventarioException('La fecha del saldo inicial no puede ser futura.', 422, ['campo' => 'fecha']);
            }

            foreach ([Configuracion::SALDO_CAJA_INICIAL => self::dinero($valor), Configuracion::SALDO_CAJA_INICIAL_FECHA => $fecha] as $clave => $v) {
                $fila = Configuracion::firstOrNew(['clave' => $clave]);
                $fila->valor = $v;
                $fila->save();
            }

            return $this->saldoInicial();
        });
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

    /** Bloqueo exclusivo de la caja: cierres, reaperturas, movimientos y saldo inicial se serializan. */
    public function bloquearCaja(): void
    {
        DB::table('secuencias')->insertOrIgnore(['nombre' => 'caja', 'valor' => 0]);
        DB::table('secuencias')->where('nombre', 'caja')->lockForUpdate()->first();
    }

    /* ───────────────────────── Resumen del día (RF-25) ───────────────────────── */

    /**
     * Resumen calculado en vivo de un día: por cuenta, totales, efectivo neto, saldo y meta.
     *
     * @return array<string, mixed>
     */
    public function resumenDia(string $fecha): array
    {
        $cuentas = $this->cuentas();
        $agregados = $this->agregados($fecha, $fecha, $cuentas);
        $saldo = $this->saldoAnterior($fecha, $cuentas);

        return $this->componer($fecha, $agregados[$fecha] ?? $this->vacio(), $cuentas, $saldo['valor'], $this->metaVentaDiaria())
            + ['saldo_anterior_origen' => $saldo['origen'], 'saldo_inicial' => $this->saldoInicial()];
    }

    /**
     * S-49: saldo con que empieza el día. Es el saldo_acumulado del último cierre vigente anterior
     * (o, si no hay, el saldo inicial desde su fecha) más el efectivo neto, calculado en vivo, de los
     * días intermedios que no se cerraron (sin eso, un día sin cerrar desaparecería del saldo).
     *
     * @return array{valor: string, origen: array{tipo: string, fecha: ?string}}
     */
    public function saldoAnterior(string $fecha, ?Collection $cuentas = null): array
    {
        $ultimo = $this->ultimoCierreVigente($fecha);
        $inicial = $this->saldoInicial();

        if ($ultimo) {
            $base = self::dinero($ultimo->saldo_acumulado);
            $desde = $ultimo->fecha->copy()->addDay()->toDateString();
            $origen = ['tipo' => 'cierre', 'fecha' => $ultimo->fecha->toDateString()];
        } elseif ($inicial && $inicial['fecha'] <= $fecha) {
            $base = $inicial['valor'];
            $desde = $inicial['fecha'];
            $origen = ['tipo' => 'inicial', 'fecha' => $inicial['fecha']];
        } else {
            return ['valor' => '0.00', 'origen' => ['tipo' => 'ninguno', 'fecha' => null]];
        }

        $hasta = Carbon::parse($fecha)->subDay()->toDateString();
        if ($desde <= $hasta) {
            $cuentas ??= $this->cuentas();
            foreach ($this->agregados($desde, $hasta, $cuentas) as $dia => $a) {
                $base = bcadd($base, $this->efectivoNeto($a, $cuentas), 2);
            }
            $origen['dias_sin_cerrar'] = true;
        }

        return ['valor' => $base, 'origen' => $origen];
    }

    /* ───────────────────────── Cuadre mensual (RF-25) ───────────────────────── */

    /**
     * Una fila por día del mes (hasta hoy) al estilo de la planilla, con fila de totales. Los días con
     * cierre vigente usan lo guardado (saldo, efectivo contado, arqueo, observación); los demás se
     * calculan en vivo y se marcan "sin cerrar". El saldo se encadena día a día.
     *
     * @return array<string, mixed>
     */
    public function resumenMes(string $mes): array
    {
        $primero = Carbon::createFromFormat('Y-m-d', $mes.'-01')->startOfDay();
        $ultimo = $primero->copy()->endOfMonth();
        $hoy = RangoFechas::hoy();
        $hasta = min($ultimo->toDateString(), $hoy);
        $desde = $primero->toDateString();

        $cuentas = $this->cuentas();
        $meta = $this->metaVentaDiaria();
        $filas = [];
        $saldoAnteriorMes = '0.00';

        if ($desde <= $hasta) {
            $agregados = $this->agregados($desde, $hasta, $cuentas);
            $cierres = CierreCaja::vigentes()->whereBetween('fecha', [$desde, $hasta])->get()->keyBy(fn (CierreCaja $c) => $c->fecha->toDateString());
            $anterior = $this->saldoAnterior($desde, $cuentas);
            $saldoAnteriorMes = $anterior['valor'];
            $saldo = $saldoAnteriorMes;
            $inicial = $this->saldoInicial();

            for ($d = $primero->copy(); $d->toDateString() <= $hasta; $d->addDay()) {
                $dia = $d->toDateString();
                // El mes empieza antes del saldo inicial: el saldo arranca ese día.
                if ($anterior['origen']['tipo'] === 'ninguno' && $inicial !== null && $dia === $inicial['fecha']) {
                    $saldo = $inicial['valor'];
                }
                $r = $this->componer($dia, $agregados[$dia] ?? $this->vacio(), $cuentas, $saldo, $meta);
                $cierre = $cierres[$dia] ?? null;
                $antesDelInicio = $inicial !== null && $dia < $inicial['fecha'];

                if ($cierre) {
                    $r['saldo_anterior'] = self::dinero($cierre->saldo_anterior);
                    $r['saldo_acumulado'] = self::dinero($cierre->saldo_acumulado);
                } elseif ($antesDelInicio) {
                    $r['saldo_acumulado'] = $saldo;
                }
                $saldo = $r['saldo_acumulado'];

                $porTipo = array_fill_keys(TipoCuentaPago::valores(), '0.00');
                foreach ($r['cuentas'] as $c) {
                    $tipo = $c['tipo'] ?? TipoCuentaPago::Efectivo->value;
                    $porTipo[$tipo] = bcadd($porTipo[$tipo], $c['ventas'], 2);
                }

                $filas[] = [
                    'fecha' => $dia,
                    'ventas_efectivo' => $porTipo['efectivo'],
                    'ventas_banco' => $porTipo['banco'],
                    'ventas_datafono' => $porTipo['datafono'],
                    'ventas_billetera' => $porTipo['billetera'],
                    'ventas_credito' => $r['ventas_credito'],
                    'otros_ingresos' => $r['otros_ingresos'],
                    'total_ingreso_dia' => $r['total_ingreso_dia'],
                    'egresos' => $r['egresos']['total'],
                    'efectivo_neto_dia' => $r['efectivo_neto_dia'],
                    'saldo_acumulado' => $r['saldo_acumulado'],
                    'meta' => $meta,
                    'faltante_meta' => $r['faltante_meta'],
                    'efectivo_contado' => $cierre ? self::dinero($cierre->efectivo_contado) : null,
                    'arqueo' => $cierre ? self::dinero($cierre->diferencia) : null,
                    'observacion' => $cierre?->observacion,
                    'cerrado' => $cierre !== null,
                    'antes_del_inicio' => $antesDelInicio,
                ];
            }
        }

        $sumar = fn (string $col) => array_reduce($filas, fn ($t, $f) => $f[$col] === null ? $t : bcadd($t, $f[$col], 2), '0.00');
        $columnas = ['ventas_efectivo', 'ventas_banco', 'ventas_datafono', 'ventas_billetera', 'ventas_credito', 'otros_ingresos',
            'total_ingreso_dia', 'egresos', 'efectivo_neto_dia', 'meta', 'faltante_meta', 'arqueo'];

        return [
            'mes' => $mes,
            'saldo_anterior' => $saldoAnteriorMes,
            'meta_venta_diaria' => $meta,
            'dias' => $filas,
            'totales' => array_combine($columnas, array_map($sumar, $columnas)) + [
                'saldo_acumulado' => $filas === [] ? $saldoAnteriorMes : end($filas)['saldo_acumulado'],
                'dias_cerrados' => count(array_filter($filas, fn ($f) => $f['cerrado'])),
                'dias_sin_cerrar' => count(array_filter($filas, fn ($f) => ! $f['cerrado'])),
            ],
        ];
    }

    /* ───────────────────────── Cálculo ───────────────────────── */

    /** Todas las cuentas (también inactivas: tienen historia), por id. */
    public function cuentas(): Collection
    {
        return CuentaPago::query()->orderBy('id')->get(['id', 'nombre', 'tipo', 'activa'])->keyBy('id');
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

        // 6. Movimientos de caja no anulados, por grupo y cuenta (RF-26).
        $q = DB::table('movimientos_caja as m')
            ->leftJoin('movimiento_caja_anulaciones as x', 'x.movimiento_id', '=', 'm.id')
            ->whereBetween('m.fecha', [$desde, $hasta])->whereNull('x.id')
            ->selectRaw('m.fecha as dia, m.grupo, m.cuenta_pago_id as cuenta, SUM(m.valor) as valor')
            ->groupBy('m.fecha', 'm.grupo', 'm.cuenta_pago_id');
        foreach ($q->get() as $f) {
            $out[$f->dia] ??= $this->vacio();
            $out[$f->dia]['movimientos'][$f->grupo][(int) $f->cuenta] = bcadd($out[$f->dia]['movimientos'][$f->grupo][(int) $f->cuenta] ?? '0', (string) $f->valor, 2);
        }

        // 7. Préstamos y abonos de empleados (RF-27, S-55, S-56): por su fecha (siempre la del registro)
        // y cuenta; una anulación resta el día en que se anula. Sin apertura ni abonos por nómina.
        $mueveCaja = fn ($w) => $w->where('m.apertura', false)->where(fn ($t) => $t->where('m.tipo', 'prestamo')->orWhere('m.forma', 'caja'));
        $altas = DB::table('movimientos_empleado as m')
            ->whereBetween('m.fecha', [$desde, $hasta])->where($mueveCaja)
            ->selectRaw('m.fecha as dia, m.tipo, m.cuenta_pago_id as cuenta, SUM(m.valor) as valor')
            ->groupBy('m.fecha', 'm.tipo', 'm.cuenta_pago_id');
        $bajas = DB::table('movimiento_empleado_anulaciones as x')
            ->join('movimientos_empleado as m', 'm.id', '=', 'x.movimiento_id')
            ->where('x.created_at', '>=', $ini)->where('x.created_at', '<', $fin)->where($mueveCaja)
            ->selectRaw($this->dia('x.created_at').' as dia, m.tipo, m.cuenta_pago_id as cuenta, -SUM(m.valor) as valor')
            ->groupBy('dia', 'm.tipo', 'cuenta');
        foreach ($altas->unionAll($bajas)->get() as $f) {
            $cuenta = $f->cuenta !== null ? (int) $f->cuenta : ($efectivoId ?? self::SIN_CUENTA);
            $poner((string) $f->dia, $f->tipo === 'prestamo' ? 'prestamos_empleados' : 'abonos_empleados', $cuenta, (string) $f->valor);
        }

        return $out;
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
        ];
    }

    /** Efectivo neto de un día a partir de sus agregados (S-49). */
    private function efectivoNeto(array $a, Collection $cuentas): string
    {
        return $this->componer('', $a, $cuentas, '0.00', '0.00')['efectivo_neto_dia'];
    }

    /**
     * Arma el resumen de un día (S-49 a S-51).
     *
     * @return array<string, mixed>
     */
    private function componer(string $fecha, array $a, Collection $cuentas, string $saldoAnterior, string $meta): array
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
            'saldo_anterior' => $saldoAnterior,
            'saldo_acumulado' => bcadd($saldoAnterior, $neto, 2),
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
