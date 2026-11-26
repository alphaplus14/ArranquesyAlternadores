<?php

namespace App\Services;

use App\Exceptions\ReglaInventarioException;
use App\Models\CuentaPago;
use App\Models\RecaudoDocumento;
use App\Models\RecaudoDocumentoAnulacion;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * RF-25 / RF-26 (S-76): recaudo de documentos (facturas o remisiones a crédito de Contapyme que no
 * se registran como ventas a crédito del sistema). Registro para el cuadre y la contadora:
 *
 * - Suma al neto de su cuenta de pago en el día y, si la cuenta es de efectivo, al efectivo esperado
 *   (CajaService lo junta con el recaudo de cartera). No cuenta para la meta ni toca la cartera de
 *   clientes, "Por cobrar", ventas ni inventario.
 * - Se asigna al día de caja que se está viendo: hoy o antes, sin cierre vigente ese día ni después
 *   (mismas reglas que los movimientos de caja, S-53). Un día cerrado es de solo lectura.
 * - Sin cliente. Un documento puede tener varios recaudos (cobros parciales): no se bloquean repetidos.
 * - Inmutable: se anula una vez, con motivo, mientras su día no esté cerrado (regla 8).
 */
class RecaudoDocumentoService
{
    public function __construct(
        private readonly CajaService $caja,
        private readonly MovimientoCajaService $movimientos,
    ) {}

    /** @param  array{tipo_documento: string, numero_documento: string, valor: string|int|float, cuenta_pago_id: int, observacion?: ?string}  $datos */
    public function registrar(string $fecha, array $datos, User $usuario): RecaudoDocumento
    {
        $cuenta = CuentaPago::find($datos['cuenta_pago_id']);
        if (! $cuenta || ! $cuenta->activa) {
            throw new ReglaInventarioException('Elija una cuenta de pago activa.', 422, ['campo' => 'cuenta_pago_id']);
        }
        if (! is_numeric($datos['valor']) || bccomp((string) $datos['valor'], '0', 2) <= 0) {
            throw new ReglaInventarioException('El valor debe ser mayor que 0.', 422, ['campo' => 'valor']);
        }

        return DB::transaction(function () use ($fecha, $datos, $cuenta, $usuario) {
            $this->caja->bloquearCaja();
            $this->movimientos->exigirFechaEditable($fecha, 'registrar recaudos');

            return RecaudoDocumento::create([
                'fecha' => $fecha,
                'tipo_documento' => $datos['tipo_documento'],
                'numero_documento' => trim($datos['numero_documento']),
                'valor' => $datos['valor'],
                'cuenta_pago_id' => $cuenta->id,
                'observacion' => self::textoOpcional($datos['observacion'] ?? null),
                'usuario_id' => $usuario->id,
            ]);
        });
    }

    /** Una sola vez y solo mientras el día del recaudo no esté cerrado (S-53). */
    public function anular(RecaudoDocumento $recaudo, string $motivo, User $usuario): RecaudoDocumentoAnulacion
    {
        return DB::transaction(function () use ($recaudo, $motivo, $usuario) {
            $this->caja->bloquearCaja();
            $recaudo = RecaudoDocumento::query()->lockForUpdate()->findOrFail($recaudo->id);

            if (RecaudoDocumentoAnulacion::where('recaudo_id', $recaudo->id)->exists()) {
                throw new ReglaInventarioException('Este recaudo ya fue anulado.', 422);
            }
            $this->movimientos->exigirFechaEditable($recaudo->fecha->toDateString(), 'anular sus recaudos');

            return RecaudoDocumentoAnulacion::create([
                'recaudo_id' => $recaudo->id,
                'motivo' => trim($motivo),
                'usuario_id' => $usuario->id,
            ]);
        });
    }

    /**
     * Consulta del contable (solo en pantalla): página de recaudos del rango, más reciente primero, y
     * totales vigentes del rango completo (no solo de la página), en total y por cuenta. Dos consultas
     * más el conteo de la página, con relaciones precargadas (sin N+1).
     *
     * @param  array{desde: string, hasta: string, tipo?: ?string, cuenta_pago_id?: ?int, q?: ?string}  $filtros
     * @return array{pagina: LengthAwarePaginator, totales: array<string, mixed>}
     */
    public function consultar(array $filtros, int $porPagina, int $pagina): array
    {
        $pagina = $this->filtrados($filtros)
            ->with('cuenta:id,nombre,tipo', 'usuario:id,name', 'anulacion.usuario:id,name')
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate($porPagina, ['*'], 'page', $pagina);

        $porCuenta = $this->filtrados($filtros)
            ->leftJoin('recaudo_documento_anulaciones as x', 'x.recaudo_id', '=', 'recaudos_documento.id')
            ->join('cuentas_pago as c', 'c.id', '=', 'recaudos_documento.cuenta_pago_id')
            ->whereNull('x.id')
            ->groupBy('c.id', 'c.nombre', 'c.tipo')
            ->orderBy('c.id')
            ->get([
                'c.id as cuenta_pago_id', 'c.nombre', 'c.tipo',
                DB::raw('SUM(recaudos_documento.valor) as total'), DB::raw('COUNT(*) as cantidad'),
            ]);

        $total = '0.00';
        $cantidad = 0;
        $filas = [];
        foreach ($porCuenta as $f) {
            $valor = CajaService::dinero((string) $f->total);
            $total = bcadd($total, $valor, 2);
            $cantidad += (int) $f->cantidad;
            $filas[] = ['cuenta_pago_id' => (int) $f->cuenta_pago_id, 'nombre' => $f->nombre, 'tipo' => $f->tipo, 'total' => $valor, 'cantidad' => (int) $f->cantidad];
        }

        return ['pagina' => $pagina, 'totales' => ['total_vigente' => $total, 'cantidad_vigente' => $cantidad, 'por_cuenta' => $filas]];
    }

    /** @param  array{desde: string, hasta: string, tipo?: ?string, cuenta_pago_id?: ?int, q?: ?string}  $filtros */
    private function filtrados(array $filtros): Builder
    {
        $q = trim((string) ($filtros['q'] ?? ''));
        $comodin = addcslashes($q, '\\%_');

        return RecaudoDocumento::query()
            ->whereBetween('recaudos_documento.fecha', [$filtros['desde'], $filtros['hasta']])
            ->when($filtros['tipo'] ?? null, fn ($w, $tipo) => $w->where('recaudos_documento.tipo_documento', $tipo))
            ->when($filtros['cuenta_pago_id'] ?? null, fn ($w, $cuenta) => $w->where('recaudos_documento.cuenta_pago_id', $cuenta))
            ->when($q !== '', fn ($w) => $w->where(fn ($o) => $o
                ->where('recaudos_documento.numero_documento', 'like', "%{$comodin}%")
                ->orWhere('recaudos_documento.observacion', 'like', "%{$comodin}%")));
    }

    private static function textoOpcional(?string $texto): ?string
    {
        $texto = trim((string) $texto);

        return $texto === '' ? null : $texto;
    }
}
