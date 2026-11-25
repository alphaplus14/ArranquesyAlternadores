<?php

namespace App\Support;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * RF-31: columnas de Excel de cada informe de RF-18 y de la bandeja de RF-30. Mismas filas y
 * totales que la pantalla: todas las filas, sin paginar, y una fila final de totales.
 */
final class InformeExcel
{
    /**
     * @param  array{filas: Collection<int, array<string, mixed>>, totales: array<string, mixed>, saldo_anterior?: string}  $informe
     */
    public static function descargar(string $informe, array $datos, string $sufijo): BinaryFileResponse
    {
        [$titulo, $columnas] = self::definicion($informe);
        $filas = $datos['filas']->map(fn (array $f) => array_map(fn (callable $valor) => $valor($f), array_values($columnas)));

        if (isset($datos['saldo_anterior'])) {
            $inicial = array_fill(0, count($columnas), null);
            $inicial[2] = 'Saldo anterior';
            $inicial[count($columnas) - 1] = self::numero($datos['saldo_anterior']);
            $filas = collect([$inicial])->concat($filas);
        }

        $filas = $filas->push(self::filaTotales($informe, $datos['totales'], count($columnas)));

        return Xlsx::descargar("{$informe}-{$sufijo}.xlsx", $titulo, array_keys($columnas), $filas);
    }

    /** @return array{0: string, 1: array<string, callable(array<string, mixed>): mixed>} */
    private static function definicion(string $informe): array
    {
        $n = self::numero(...);
        $rangos = collect(config('cartera.rangos'))
            ->mapWithKeys(fn (string $nombre, string $clave) => ["Días {$nombre}" => fn ($f) => $n($f['rangos'][$clave])])
            ->all();
        $saldos = [
            'Cargos' => fn ($f) => $n($f['cargos']),
            'Abonos o pagos' => fn ($f) => $n($f['abonos']),
            'Saldo' => fn ($f) => $n($f['saldo']),
            'Saldo a favor' => fn ($f) => $n($f['a_favor']),
            'Vencido' => fn ($f) => $n($f['vencido']),
            ...$rangos,
        ];
        $estadoCuenta = [
            'Fecha' => fn ($f) => $f['fecha'],
            'Tipo' => fn ($f) => $f['tipo'],
            'Detalle' => fn ($f) => $f['detalle'],
            'Cargo' => fn ($f) => $n($f['cargo']),
            'Abono o pago' => fn ($f) => $n($f['abono']),
            'Saldo' => fn ($f) => $n($f['saldo']),
        ];

        return match ($informe) {
            'compras' => ['Compras', [
                'Fecha' => fn ($f) => $f['fecha'],
                'Tipo' => fn ($f) => $f['tipo'],
                'Número' => fn ($f) => $f['numero_documento'],
                'Proveedor' => fn ($f) => $f['proveedor']['nombre'],
                'Forma de pago' => fn ($f) => $f['forma_pago'],
                'Subtotal' => fn ($f) => $n($f['subtotal']),
                'Descuento' => fn ($f) => $n($f['descuento']),
                'Base gravada' => fn ($f) => $n($f['base_gravada']),
                'Base sin IVA' => fn ($f) => $n($f['base_sin_iva']),
                'IVA' => fn ($f) => $n($f['iva']),
                'Total' => fn ($f) => $n($f['total']),
            ]],
            'ventas-credito' => ['Ventas a crédito', [
                'Venta' => fn ($f) => $f['id'],
                'Fecha' => fn ($f) => $f['fecha'],
                'Vence' => fn ($f) => $f['vence_el'],
                'Cliente' => fn ($f) => $f['cliente']['nombre'],
                'Factura Contapyme' => fn ($f) => $f['factura_contapyme'],
                'Ítems' => fn ($f) => $f['items'],
                'Base' => fn ($f) => $n($f['base']),
                'IVA' => fn ($f) => $n($f['iva']),
                'Total' => fn ($f) => $n($f['total']),
            ]],
            'cartera-clientes' => ['Cartera de clientes', [
                'Cliente' => fn ($f) => $f['cliente']['nombre'],
                'Documento' => fn ($f) => $f['cliente']['documento'] ?? null,
                ...$saldos,
            ]],
            'cuentas-por-pagar' => ['Cuentas por pagar', [
                'Proveedor' => fn ($f) => $f['proveedor']['nombre'],
                'NIT' => fn ($f) => $f['proveedor']['nit'] ?? null,
                ...$saldos,
            ]],
            'estado-cuenta-cliente' => ['Estado de cuenta cliente', $estadoCuenta],
            'estado-cuenta-proveedor' => ['Estado de cuenta proveedor', $estadoCuenta],
            'remisiones-pendientes' => ['Remisiones pendientes', [
                'Número' => fn ($f) => $f['numero_documento'],
                'Proveedor' => fn ($f) => $f['proveedor']['nombre'],
                'Fecha' => fn ($f) => $f['fecha'],
                'Forma de pago' => fn ($f) => $f['forma_pago'],
                'Total' => fn ($f) => $n($f['total']),
                'Días' => fn ($f) => $f['dias'],
                'Factura en borrador' => fn ($f) => $f['factura_borrador']['numero_documento'] ?? null,
            ]],
            'diferencias-remision' => ['Diferencias remisión', [
                'Factura' => fn ($f) => $f['numero_documento'],
                'Proveedor' => fn ($f) => $f['proveedor']['nombre'],
                'Confirmada' => fn ($f) => (string) $f['confirmado_at'],
                'Aceptó' => fn ($f) => $f['confirmado_por']['name'] ?? null,
                'Motivo' => fn ($f) => $f['motivo'],
                'Remisiones' => fn ($f) => collect($f['remisiones'])->pluck('numero_documento')->implode(', '),
                'Faltantes' => fn ($f) => collect($f['detalle'])
                    ->map(fn ($d) => "{$d['codigo_referencia']}: recibido {$d['recibido']}, facturado {$d['facturado']}")
                    ->implode('; '),
            ]],
            'vencimientos' => ['Vencimientos', [
                'Tipo' => fn ($f) => $f['tipo'] === 'por_pagar' ? 'Por pagar' : 'Por cobrar',
                'Estado' => fn ($f) => $f['estado'] === 'vencida' ? 'Vencida' : 'Por vencer',
                'Proveedor o cliente' => fn ($f) => $f['titular']['nombre'],
                'Documento' => fn ($f) => $f['documento'],
                'Fecha' => fn ($f) => $f['fecha'],
                'Vence' => fn ($f) => $f['vence_el'],
                'Días de mora' => fn ($f) => $f['dias_vencido'],
                'Días para vencer' => fn ($f) => $f['dias_para_vencer'],
                'Valor' => fn ($f) => $n($f['valor']),
                'Pendiente' => fn ($f) => $n($f['pendiente']),
            ]],
            default => throw new InvalidArgumentException("Informe sin exportación: {$informe}"),
        };
    }

    /**
     * Fila "Totales": cada total va bajo la columna del campo que suma.
     *
     * @param  array<string, mixed>  $totales
     * @return list<string|int|float|null>
     */
    private static function filaTotales(string $informe, array $totales, int $ancho): array
    {
        $fila = array_fill(0, $ancho, null);
        $fila[0] = 'Totales';
        $n = self::numero(...);
        $poner = function (array $posiciones) use (&$fila, $totales, $n) {
            foreach ($posiciones as $campo => $i) {
                $fila[$i] = $n(data_get($totales, $campo));
            }
        };

        $saldos = ['cargos' => 2, 'abonos' => 3, 'saldo' => 4, 'a_favor' => 5, 'vencido' => 6];
        foreach (array_keys(config('cartera.rangos')) as $k => $rango) {
            $saldos["rangos.{$rango}"] = 7 + $k;
        }

        match ($informe) {
            'compras' => $poner(['subtotal' => 5, 'descuento' => 6, 'base_gravada' => 7, 'base_sin_iva' => 8, 'iva' => 9, 'total' => 10]),
            'ventas-credito' => $poner(['base' => 6, 'iva' => 7, 'total' => 8]),
            'cartera-clientes', 'cuentas-por-pagar' => $poner($saldos),
            'estado-cuenta-cliente', 'estado-cuenta-proveedor' => $poner(['cargo' => 3, 'abono' => 4, 'saldo_final' => 5]),
            'remisiones-pendientes' => $poner(['total' => 4]),
            'diferencias-remision' => $fila[1] = ($totales['facturas'] ?? 0).' facturas',
            'vencimientos' => $poner(['todas.pendiente' => 9]),
        };

        return $fila;
    }

    private static function numero(mixed $valor): int|float|null
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        $f = (float) $valor;

        return floor($f) === $f ? (int) $f : $f;
    }
}
