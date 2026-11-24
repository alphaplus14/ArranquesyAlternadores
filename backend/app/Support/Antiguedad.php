<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * RF-15 / RF-17 / RF-18: saldo y antigüedad de un cliente o un proveedor a una fecha de corte.
 *
 * Lo abonado (o pagado) se aplica FIFO a los documentos que vencen primero (S-12), sin guardar
 * la asignación. Cada documento con saldo cae en un rango de días vencidos contra su vence_el
 * (S-13; ver config/cartera.php). Si lo abonado supera los cargos, el saldo es negativo y la
 * diferencia se muestra como saldo a favor.
 *
 * Cifras en cadenas decimales con bcmath, como TotalesIngreso.
 */
final class Antiguedad
{
    private const ESCALA = 2;

    /**
     * @param  array<string, string>  $rangos  pendiente por rango (claves de config('cartera.rangos'))
     * @param  list<array{id:int, fecha:string, vence_el:?string, valor:string, pendiente:string, dias_vencido:int, rango:string}>  $documentos  solo los que tienen saldo
     */
    private function __construct(
        public readonly string $cargos,
        public readonly string $abonos,
        public readonly string $saldo,
        public readonly string $aFavor,
        public readonly string $vencido,
        public readonly array $rangos,
        public readonly array $documentos,
    ) {}

    /**
     * @param  iterable<array{id:int, fecha:string, vence_el:?string, valor:string|int|float}>  $documentos
     */
    public static function calcular(iterable $documentos, string|int|float $abonado, CarbonInterface $corte): self
    {
        $docs = [];
        foreach ($documentos as $d) {
            $docs[] = [...$d, 'valor' => self::decimal($d['valor'])];
        }

        // Primero lo que vence antes; sin vencimiento, su fecha.
        usort($docs, fn ($a, $b) => [$a['vence_el'] ?? $a['fecha'], $a['fecha'], $a['id']]
            <=> [$b['vence_el'] ?? $b['fecha'], $b['fecha'], $b['id']]);

        $abonos = self::decimal($abonado);
        $cargos = '0';
        $porAplicar = $abonos;
        $rangos = array_fill_keys(array_keys(config('cartera.rangos')), '0.00');
        $vencido = '0';
        $pendientes = [];
        $dia = Carbon::instance($corte)->startOfDay();

        foreach ($docs as $doc) {
            $cargos = bcadd($cargos, $doc['valor'], self::ESCALA);
            $aplicado = bccomp($porAplicar, $doc['valor'], self::ESCALA) >= 0 ? $doc['valor'] : $porAplicar;
            $porAplicar = bcsub($porAplicar, $aplicado, self::ESCALA);
            $pendiente = bcsub($doc['valor'], $aplicado, self::ESCALA);

            if (bccomp($pendiente, '0', self::ESCALA) <= 0) {
                continue;
            }

            $vence = Carbon::parse($doc['vence_el'] ?? $doc['fecha'])->startOfDay();
            $dias = (int) $vence->diffInDays($dia, false);
            $rango = self::rango($dias);
            $rangos[$rango] = bcadd($rangos[$rango], $pendiente, self::ESCALA);
            if ($dias > 0) {
                $vencido = bcadd($vencido, $pendiente, self::ESCALA);
            }

            $pendientes[] = [
                'id' => $doc['id'],
                'fecha' => $doc['fecha'],
                'vence_el' => $doc['vence_el'],
                'valor' => $doc['valor'],
                'pendiente' => $pendiente,
                'dias_vencido' => max($dias, 0),
                'rango' => $rango,
            ];
        }

        $saldo = bcsub($cargos, $abonos, self::ESCALA);

        return new self(
            cargos: bcadd($cargos, '0', self::ESCALA),
            abonos: $abonos,
            saldo: $saldo,
            aFavor: bccomp($saldo, '0', self::ESCALA) < 0 ? bcmul($saldo, '-1', self::ESCALA) : '0.00',
            vencido: bcadd($vencido, '0', self::ESCALA),
            rangos: $rangos,
            documentos: $pendientes,
        );
    }

    /** @return array{cargos:string, abonos:string, saldo:string, a_favor:string, vencido:string, rangos:array<string,string>} */
    public function resumen(): array
    {
        return [
            'cargos' => $this->cargos,
            'abonos' => $this->abonos,
            'saldo' => $this->saldo,
            'a_favor' => $this->aFavor,
            'vencido' => $this->vencido,
            'rangos' => $this->rangos,
        ];
    }

    /** S-13: al día, 1-30, 31-60, 61-90 y más de 90 días vencidos. */
    private static function rango(int $dias): string
    {
        return match (true) {
            $dias <= 0 => 'al_dia',
            $dias <= 30 => 'd1_30',
            $dias <= 60 => 'd31_60',
            $dias <= 90 => 'd61_90',
            default => 'mas_90',
        };
    }

    private static function decimal(string|int|float|null $valor): string
    {
        if ($valor === null || $valor === '') {
            return '0.00';
        }

        return bcadd(is_string($valor) ? $valor : number_format((float) $valor, 2, '.', ''), '0', self::ESCALA);
    }
}
