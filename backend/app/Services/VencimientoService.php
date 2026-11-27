<?php

namespace App\Services;

use App\Enums\TipoIngreso;
use App\Models\Cliente;
use App\Models\Proveedor;
use App\Support\Antiguedad;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * RF-30: bandeja de vencimientos. Documentos con saldo pendiente hoy que ya vencieron o vencen
 * dentro de los próximos `cartera.aviso_dias` días (S-28):
 *  - por pagar: ingresos a crédito de proveedores, con los pagos a cada factura (y, en pagos
 *    anteriores a S-79 sin factura, su FIFO de lectura);
 *  - por cobrar: ventas a crédito cerradas de clientes, con sus abonos (FIFO de lectura en los antiguos).
 * Solo lectura. Un documento cancelado (pendiente 0) no aparece.
 */
class VencimientoService
{
    public const POR_PAGAR = 'por_pagar';

    public const POR_COBRAR = 'por_cobrar';

    private const ESCALA = 2;

    public function __construct(private readonly CarteraService $cartera) {}

    /**
     * @return array{filas: Collection<int, array<string, mixed>>, totales: array<string, mixed>}
     */
    /**
     * S-88: filtros opcionales sobre la bandeja ya calculada (sin recalcular por fila): estado
     * (vencidas | por_vencer), texto (titular o documento, sin tildes ni mayúsculas), rango de
     * vencimiento y orden (vence_asc = lo más vencido primero, pendiente_desc, titular). Los totales
     * son los de lo filtrado.
     *
     * @param  array{estado?: ?string, q?: ?string, vence_desde?: ?string, vence_hasta?: ?string, orden?: ?string}  $filtros
     */
    public function bandeja(CarbonInterface $hoy, int $dias, ?string $tipo = null, array $filtros = []): array
    {
        $limite = Carbon::instance($hoy)->startOfDay()->addDays($dias)->toDateString();
        $filas = collect();

        if ($tipo === null || $tipo === self::POR_PAGAR) {
            $documentos = $this->cartera->comprasCredito(null, $hoy);
            $nombres = Proveedor::query()->whereKey($documentos->keys())->pluck('nombre', 'id');
            $filas = $filas->concat($this->filas(
                $documentos, $this->cartera->pagado(null, $hoy), $nombres, $hoy, $limite, self::POR_PAGAR,
                fn (array $d) => ($d['tipo'] === TipoIngreso::Factura->value ? 'Factura ' : 'Remisión ').$d['numero_documento'],
            ));
        }

        if ($tipo === null || $tipo === self::POR_COBRAR) {
            $documentos = $this->cartera->ventasCredito(null, $hoy);
            $nombres = Cliente::query()->whereKey($documentos->keys())->pluck('nombre', 'id');
            $filas = $filas->concat($this->filas(
                $documentos, $this->cartera->abonado(null, $hoy), $nombres, $hoy, $limite, self::POR_COBRAR,
                // S-73: una venta a crédito de solo valor se nombra por su documento.
                fn (array $d) => $d['documento'] ?? 'Venta #'.$d['id'],
            ));
        }

        $estado = $filtros['estado'] ?? null;
        $q = ReporteService::normalizar($filtros['q'] ?? '');
        $filas = $filas->filter(fn ($f) => (! in_array($estado, ['vencidas', 'por_vencer'], true) || $f['estado'] === ($estado === 'vencidas' ? 'vencida' : 'por_vencer'))
            && ($q === '' || str_contains(ReporteService::normalizar($f['titular']['nombre']), $q) || str_contains(ReporteService::normalizar($f['documento']), $q))
            && (empty($filtros['vence_desde']) || $f['vence_el'] >= $filtros['vence_desde'])
            && (empty($filtros['vence_hasta']) || $f['vence_el'] <= $filtros['vence_hasta']));

        $filas = match ($filtros['orden'] ?? null) {
            'pendiente_desc' => $filas->sortByDesc(fn ($f) => (float) $f['pendiente']),
            'titular' => $filas->sortBy(fn ($f) => [ReporteService::normalizar($f['titular']['nombre']), $f['vence_el']]),
            // Vencidas primero (la de más mora arriba), luego las que vencen antes.
            default => $filas->sortBy(fn ($f) => [$f['estado'] === 'vencida' ? 0 : 1, $f['estado'] === 'vencida' ? -$f['dias_vencido'] : $f['dias_para_vencer'], $f['titular']['nombre']]),
        };
        $filas = $filas->values();

        return ['filas' => $filas, 'totales' => $this->totales($filas)];
    }

    /**
     * @param  Collection<int, Collection<int, array<string, mixed>>>  $documentos  por titular
     * @param  array<int, string>  $abonado  por titular
     * @param  Collection<int, string>  $nombres  por titular
     * @return Collection<int, array<string, mixed>>
     */
    private function filas(Collection $documentos, array $abonado, Collection $nombres, CarbonInterface $hoy, string $limite, string $tipo, callable $nombreDocumento): Collection
    {
        return $documentos->flatMap(fn (Collection $docs, int $titularId) => collect(
            Antiguedad::calcular($docs, $abonado[$titularId] ?? '0', $hoy)->documentos
        )
            ->filter(fn (array $d) => ($d['vence_el'] ?? $d['fecha']) <= $limite)
            ->map(fn (array $d) => [
                'tipo' => $tipo,
                'estado' => $d['dias_vencido'] > 0 ? 'vencida' : 'por_vencer',
                'titular' => ['id' => $titularId, 'nombre' => $nombres[$titularId] ?? ''],
                'documento_id' => $d['id'],
                'documento' => $nombreDocumento($d),
                'fecha' => $d['fecha'],
                'vence_el' => $d['vence_el'] ?? $d['fecha'],
                'dias_vencido' => $d['dias_vencido'],
                'dias_para_vencer' => $d['dias_para_vencer'],
                'valor' => $d['valor'],
                'pendiente' => $d['pendiente'],
                'abonado' => $d['abonado'], // S-79: pagos parciales ya hechos al documento
            ]));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $filas
     * @return array<string, array{cantidad:int, pendiente:string}>
     */
    private function totales(Collection $filas): array
    {
        $grupo = fn (Collection $f) => [
            'cantidad' => $f->count(),
            'pendiente' => $f->reduce(fn ($s, $x) => bcadd($s, $x['pendiente'], self::ESCALA), '0.00'),
        ];

        return [
            'vencidas' => $grupo($filas->where('estado', 'vencida')),
            'por_vencer' => $grupo($filas->where('estado', 'por_vencer')),
            'todas' => $grupo($filas),
        ];
    }
}
