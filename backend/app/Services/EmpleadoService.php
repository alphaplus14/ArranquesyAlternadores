<?php

namespace App\Services;

use App\Enums\FormaAbonoEmpleado;
use App\Enums\TipoMovimientoEmpleado;
use App\Exceptions\ReglaInventarioException;
use App\Models\CuentaPago;
use App\Models\Empleado;
use App\Models\MovimientoEmpleado;
use App\Models\MovimientoEmpleadoAnulacion;
use App\Models\User;
use App\Support\RangoFechas;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * RF-27 (S-55 a S-57): cuenta de empleados. Préstamos del local a sus empleados y abonos con que
 * los pagan. Cartera independiente de la de clientes (no toca CarteraService ni el panel).
 *
 * - Saldo = préstamos no anulados − abonos no anulados. Nunca negativo (S-57: sin saldo a favor):
 *   un abono mayor que la deuda se rechaza, y anular un préstamo que dejaría el saldo negativo también.
 * - S-56: el préstamo sale siempre de la cuenta Efectivo; el abono es por `caja` (a una cuenta activa)
 *   o por `nomina` (solo se registra; no mueve caja).
 * - S-55: lo que mueve efectivo entra a la caja de su fecha, que es siempre hoy (S-56); su anulación,
 *   al día en que se anula, como los abonos de clientes. Por eso exige el día abierto. La apertura y el abono por
 *   nómina no tocan la caja.
 * - Préstamos, abonos y anulaciones son inmutables (regla 8). El bloqueo de la fila del empleado
 *   serializa los cambios de su saldo.
 */
class EmpleadoService
{
    public function __construct(private readonly CajaService $caja) {}

    /* ───────────────────────── Empleados ───────────────────────── */

    /**
     * Solo admin (ruta). Con saldo de apertura, crea un préstamo `apertura` que no afecta la caja (la
     * plata salió antes del sistema). La apertura solo existe aquí: una vez, al crear.
     *
     * @param  array{nombre: string, documento?: ?string, saldo_apertura?: int|string|null, fecha_apertura?: ?string}  $datos
     */
    public function crear(array $datos, User $usuario): Empleado
    {
        $apertura = $datos['saldo_apertura'] ?? null;
        $fecha = $datos['fecha_apertura'] ?? RangoFechas::hoy();
        if ($apertura !== null && $fecha > RangoFechas::hoy()) {
            throw new ReglaInventarioException('La fecha del saldo de apertura no puede ser futura.', 422, ['campo' => 'fecha_apertura']);
        }

        return DB::transaction(function () use ($datos, $apertura, $fecha, $usuario) {
            $empleado = Empleado::create([
                'nombre' => trim($datos['nombre']),
                'documento' => self::textoOpcional($datos['documento'] ?? null),
            ]);

            if ($apertura !== null && bccomp((string) $apertura, '0', 2) > 0) {
                MovimientoEmpleado::create([
                    'empleado_id' => $empleado->id,
                    'tipo' => TipoMovimientoEmpleado::Prestamo,
                    'fecha' => $fecha,
                    'valor' => $apertura,
                    'cuenta_pago_id' => CuentaPago::efectivo()->id,
                    'apertura' => true,
                    'nota' => 'Saldo de apertura',
                    'usuario_id' => $usuario->id,
                ]);
            }

            return $empleado;
        });
    }

    /**
     * Solo admin (ruta). Nombre, documento y activo; Auditable deja cada cambio (RF-11). No se desactiva
     * con saldo distinto de 0.
     *
     * @param  array{nombre?: string, documento?: ?string, activo?: bool}  $datos
     */
    public function actualizar(Empleado $empleado, array $datos): Empleado
    {
        return DB::transaction(function () use ($empleado, $datos) {
            $empleado = Empleado::query()->lockForUpdate()->findOrFail($empleado->id);

            if (array_key_exists('nombre', $datos)) {
                $datos['nombre'] = trim($datos['nombre']);
            }
            if (array_key_exists('documento', $datos)) {
                $datos['documento'] = self::textoOpcional($datos['documento']);
            }

            if (($datos['activo'] ?? true) === false && $empleado->activo) {
                $saldo = $this->saldo($empleado->id);
                if (bccomp($saldo, '0', 2) !== 0) {
                    throw new ReglaInventarioException(
                        "No se puede desactivar a {$empleado->nombre}: tiene un saldo pendiente de ".self::pesos($saldo).'. Primero debe quedar en $0.',
                        422,
                        ['campo' => 'activo', 'saldo' => $saldo],
                    );
                }
            }

            $empleado->update($datos);

            return $empleado;
        });
    }

    /** Saldo vigente de un empleado: una consulta agrupada. */
    public function saldo(int $empleadoId): string
    {
        $valor = DB::table('movimientos_empleado as m')
            ->leftJoin('movimiento_empleado_anulaciones as x', 'x.movimiento_id', '=', 'm.id')
            ->where('m.empleado_id', $empleadoId)->whereNull('x.id')
            ->selectRaw("COALESCE(SUM(CASE WHEN m.tipo = 'prestamo' THEN m.valor ELSE -m.valor END), 0) as saldo")
            ->value('saldo');

        return CajaService::dinero((string) $valor);
    }

    /* ───────────────────────── Préstamos y abonos (mostrador) ───────────────────────── */

    /**
     * S-56: siempre de la cuenta Efectivo; entra a la caja de hoy como salida de efectivo.
     *
     * @param  array{valor: int|string, nota?: ?string}  $datos
     */
    public function registrarPrestamo(Empleado $empleado, array $datos, User $usuario): MovimientoEmpleado
    {
        $fecha = $this->validarFechaYValor($datos);

        return DB::transaction(function () use ($empleado, $datos, $fecha, $usuario) {
            $empleado = $this->bloquearActivo($empleado, 'prestarle');
            $this->caja->exigirDiaAbierto('registrar préstamos a empleados');

            return MovimientoEmpleado::create([
                'empleado_id' => $empleado->id,
                'tipo' => TipoMovimientoEmpleado::Prestamo,
                'fecha' => $fecha,
                'valor' => $datos['valor'],
                'cuenta_pago_id' => CuentaPago::efectivo()->id,
                'nota' => self::textoOpcional($datos['nota'] ?? null),
                'usuario_id' => $usuario->id,
            ]);
        });
    }

    /**
     * S-56: por caja (cuenta activa obligatoria; entra a la caja de hoy) o por nómina (sin cuenta, sin
     * efecto en la caja). S-57: no supera la deuda. El mensaje no dice cuánto debe: el mostrador no ve
     * saldos.
     *
     * @param  array{valor: int|string, forma: string, cuenta_pago_id?: ?int, nota?: ?string}  $datos
     */
    public function registrarAbono(Empleado $empleado, array $datos, User $usuario): MovimientoEmpleado
    {
        $fecha = $this->validarFechaYValor($datos);
        $forma = FormaAbonoEmpleado::from($datos['forma']);
        $cuentaId = $datos['cuenta_pago_id'] ?? null;

        if ($forma === FormaAbonoEmpleado::Nomina && $cuentaId !== null) {
            throw new ReglaInventarioException('Un abono por nómina no lleva cuenta: no entra dinero a la caja.', 422, ['campo' => 'cuenta_pago_id']);
        }
        if ($forma === FormaAbonoEmpleado::Caja) {
            $cuenta = $cuentaId !== null ? CuentaPago::find($cuentaId) : null;
            if (! $cuenta || ! $cuenta->activa) {
                throw new ReglaInventarioException('Elija la cuenta activa donde entra el abono.', 422, ['campo' => 'cuenta_pago_id']);
            }
        }

        return DB::transaction(function () use ($empleado, $datos, $fecha, $forma, $cuentaId, $usuario) {
            $empleado = $this->bloquearActivo($empleado, 'registrarle abonos');

            if (bccomp((string) $datos['valor'], $this->saldo($empleado->id), 2) > 0) {
                throw new ReglaInventarioException(
                    "El abono supera lo que debe {$empleado->nombre}. Verifique el valor con el administrador.",
                    422,
                    ['campo' => 'valor'],
                );
            }
            if ($forma === FormaAbonoEmpleado::Caja) {
                $this->caja->exigirDiaAbierto('registrar abonos de empleados');
            }

            return MovimientoEmpleado::create([
                'empleado_id' => $empleado->id,
                'tipo' => TipoMovimientoEmpleado::Abono,
                'fecha' => $fecha,
                'valor' => $datos['valor'],
                'forma' => $forma,
                'cuenta_pago_id' => $forma === FormaAbonoEmpleado::Caja ? $cuentaId : null,
                'nota' => self::textoOpcional($datos['nota'] ?? null),
                'usuario_id' => $usuario->id,
            ]);
        });
    }

    /* ───────────────────────── Anulación (admin) ───────────────────────── */

    /**
     * Solo admin (ruta). Una sola vez, con motivo. Si el movimiento movió efectivo, la anulación entra
     * a la caja de hoy (exige el día abierto). Anular un préstamo que dejaría el saldo negativo se
     * rechaza (S-57): primero se anulan los abonos que lo pagaron.
     */
    public function anular(MovimientoEmpleado $movimiento, string $motivo, User $usuario): MovimientoEmpleadoAnulacion
    {
        return DB::transaction(function () use ($movimiento, $motivo, $usuario) {
            Empleado::query()->lockForUpdate()->findOrFail($movimiento->empleado_id);
            $movimiento = MovimientoEmpleado::query()->findOrFail($movimiento->id);

            if (MovimientoEmpleadoAnulacion::where('movimiento_id', $movimiento->id)->exists()) {
                throw new ReglaInventarioException('Este movimiento ya fue anulado.', 422);
            }

            if ($movimiento->tipo === TipoMovimientoEmpleado::Prestamo) {
                $queda = bcsub($this->saldo($movimiento->empleado_id), (string) $movimiento->valor, 2);
                if (bccomp($queda, '0', 2) < 0) {
                    throw new ReglaInventarioException(
                        'No se puede anular este préstamo: los abonos registrados dejarían el saldo en '.self::pesos($queda).'. Anule primero los abonos que lo pagaron.',
                        422,
                        ['saldo_resultante' => $queda],
                    );
                }
            }

            if (self::mueveCaja($movimiento)) {
                $this->caja->exigirDiaAbierto('anular movimientos de empleados');
            }

            return MovimientoEmpleadoAnulacion::create([
                'movimiento_id' => $movimiento->id,
                'motivo' => trim($motivo),
                'usuario_id' => $usuario->id,
            ]);
        });
    }

    /** Lo que mueve la caja: préstamos (no apertura) y abonos por caja. */
    public static function mueveCaja(MovimientoEmpleado $m): bool
    {
        return $m->tipo === TipoMovimientoEmpleado::Prestamo ? ! $m->apertura : $m->forma === FormaAbonoEmpleado::Caja;
    }

    /* ───────────────────────── Consultas (admin) ───────────────────────── */

    /**
     * Movimientos y anulaciones en orden de fecha con saldo corrido. Una anulación es su propia fila
     * en el día en que se hizo, con el signo contrario al movimiento anulado (que sigue visible).
     *
     * @return array<string, mixed>
     */
    public function estadoCuenta(Empleado $empleado): array
    {
        $movimientos = MovimientoEmpleado::query()
            ->where('empleado_id', $empleado->id)
            ->with('cuenta:id,nombre', 'usuario:id,name', 'anulacion.usuario:id,name')
            ->orderBy('fecha')->orderBy('id')
            ->get();

        $filas = [];
        foreach ($movimientos as $m) {
            $esPrestamo = $m->tipo === TipoMovimientoEmpleado::Prestamo;
            $filas[] = [
                'clave' => 'm'.$m->id,
                'tipo' => $m->tipo->value,
                'movimiento_id' => $m->id,
                'fecha' => $m->fecha->toDateString(),
                'registrado_at' => $m->created_at?->toIso8601String(),
                'prestamo' => $esPrestamo ? CajaService::dinero((string) $m->valor) : null,
                'abono' => $esPrestamo ? null : CajaService::dinero((string) $m->valor),
                'forma' => $m->forma?->value,
                'cuenta' => $m->cuenta?->nombre,
                'apertura' => $m->apertura,
                'nota' => $m->nota,
                'usuario' => $m->usuario?->name,
                'anulado' => $m->anulacion !== null,
                'anulacion' => $m->anulacion ? [
                    'motivo' => $m->anulacion->motivo,
                    'fecha' => RangoFechas::diaLocal($m->anulacion->created_at),
                    'usuario' => $m->anulacion->usuario?->name,
                ] : null,
                '_orden' => [$m->fecha->toDateString(), $m->created_at?->getTimestamp() ?? 0, 0, $m->id],
                '_efecto' => $esPrestamo ? (string) $m->valor : '-'.$m->valor,
            ];

            if ($m->anulacion) {
                $dia = RangoFechas::diaLocal($m->anulacion->created_at);
                $filas[] = [
                    'clave' => 'a'.$m->anulacion->id,
                    'tipo' => 'anulacion',
                    'movimiento_id' => $m->id,
                    'anula' => $m->tipo->value,
                    'fecha' => $dia,
                    'registrado_at' => $m->anulacion->created_at?->toIso8601String(),
                    // Anular un préstamo baja el saldo (columna abono) y anular un abono lo sube (préstamo).
                    'prestamo' => $esPrestamo ? null : CajaService::dinero((string) $m->valor),
                    'abono' => $esPrestamo ? CajaService::dinero((string) $m->valor) : null,
                    'motivo' => $m->anulacion->motivo,
                    'usuario' => $m->anulacion->usuario?->name,
                    '_orden' => [$dia, $m->anulacion->created_at?->getTimestamp() ?? 0, 1, $m->anulacion->id],
                    '_efecto' => $esPrestamo ? '-'.$m->valor : (string) $m->valor,
                ];
            }
        }

        usort($filas, fn ($a, $b) => $a['_orden'] <=> $b['_orden']);

        $saldo = '0.00';
        foreach ($filas as &$f) {
            $saldo = bcadd($saldo, $f['_efecto'], 2);
            $f['saldo'] = $saldo;
            unset($f['_orden'], $f['_efecto']);
        }
        unset($f);

        return [
            'empleado' => $empleado->only('id', 'nombre', 'documento', 'activo'),
            'saldo' => $saldo,
            'movimientos' => $filas,
        ];
    }

    /**
     * Saldo por empleado y fecha del último movimiento (activos primero), con el total prestado
     * pendiente de todos. Una consulta agrupada por empleado más una para el total.
     */
    public function resumen(?string $q, int $porPagina = 50): array
    {
        $agregado = $this->agregadoPorEmpleado();

        $pagina = Empleado::query()
            ->leftJoinSub($agregado, 'a', 'a.empleado_id', '=', 'empleados.id')
            ->when($q, fn ($w) => $w->where(fn ($s) => $s->where('empleados.nombre', 'like', "%{$q}%")->orWhere('empleados.documento', 'like', "%{$q}%")))
            ->select('empleados.id', 'empleados.nombre', 'empleados.documento', 'empleados.activo')
            ->selectRaw('COALESCE(a.saldo, 0) as saldo, a.ultimo_movimiento')
            ->orderByDesc('empleados.activo')->orderBy('empleados.nombre')
            ->paginate($porPagina);

        $totales = DB::query()->fromSub($agregado, 'a')
            ->selectRaw('COALESCE(SUM(a.saldo), 0) as total, SUM(CASE WHEN a.saldo <> 0 THEN 1 ELSE 0 END) as con_saldo')
            ->first();

        return [
            'pagina' => $pagina,
            'total_pendiente' => CajaService::dinero((string) $totales->total),
            'empleados_con_saldo' => (int) $totales->con_saldo,
        ];
    }

    private function agregadoPorEmpleado(): \Illuminate\Database\Query\Builder
    {
        return DB::table('movimientos_empleado as m')
            ->leftJoin('movimiento_empleado_anulaciones as x', 'x.movimiento_id', '=', 'm.id')
            ->selectRaw("m.empleado_id, SUM(CASE WHEN x.id IS NOT NULL THEN 0 WHEN m.tipo = 'prestamo' THEN m.valor ELSE -m.valor END) as saldo, MAX(m.fecha) as ultimo_movimiento")
            ->groupBy('m.empleado_id');
    }

    /* ───────────────────────── Apoyo ───────────────────────── */

    private function bloquearActivo(Empleado $empleado, string $accion): Empleado
    {
        $empleado = Empleado::query()->lockForUpdate()->findOrFail($empleado->id);
        if (! $empleado->activo) {
            throw new ReglaInventarioException("{$empleado->nombre} está inactivo: no se puede {$accion}.", 422);
        }

        return $empleado;
    }

    /** S-56: la fecha es siempre hoy (día de caja en que entra). Valor > 0. Devuelve la fecha. */
    private function validarFechaYValor(array $datos): string
    {
        $fecha = RangoFechas::hoy();
        if (! is_numeric($datos['valor'] ?? null) || bccomp((string) $datos['valor'], '0', 2) <= 0) {
            throw new ReglaInventarioException('El valor debe ser mayor que 0.', 422, ['campo' => 'valor']);
        }

        return $fecha;
    }

    private static function pesos(string $valor): string
    {
        $n = (float) $valor;

        return ($n < 0 ? '-$' : '$').number_format(abs($n), 0, ',', '.');
    }

    private static function textoOpcional(?string $texto): ?string
    {
        $texto = trim((string) $texto);

        return $texto === '' ? null : $texto;
    }
}
