<?php

namespace App\Services;

use App\Enums\EstadoIngreso;
use App\Enums\EstadoVenta;
use App\Enums\FormaPago;
use App\Models\Cliente;
use App\Models\Proveedor;
use App\Support\Antiguedad;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RF-15 / RF-17 / RF-18: saldos de clientes y proveedores a una fecha de corte. Solo lectura:
 * no toca existencias.
 *
 * Saldo del cliente = ventas a crédito cerradas (S-09), netas de los ítems reversados hasta el
 * corte, menos los abonos no anulados hasta el corte. El valor de cada venta es la suma de sus
 * precios (S-24); los ítems sin precio (ventas anteriores a RF-14) no cuentan.
 *
 * Saldo del proveedor = ingresos a crédito confirmados hasta el corte, por su total fijado al
 * confirmar, menos los pagos no anulados hasta el corte. Una remisión cubierta por una factura
 * confirmada no cuenta: cuenta la factura (RF-19). Los ingresos confirmados antes de RF-16 no
 * tienen total y no cuentan.
 */
class CarteraService
{
    public function saldoProveedor(Proveedor $proveedor, ?CarbonInterface $corte = null): Antiguedad
    {
        $corte ??= Carbon::today();

        return Antiguedad::calcular(
            $this->comprasCredito([$proveedor->id], $corte)->get($proveedor->id, collect()),
            $this->pagado([$proveedor->id], $corte)[$proveedor->id] ?? '0',
            $corte,
        );
    }

    /**
     * Ingresos que generan deuda hasta el corte, agrupados por proveedor.
     *
     * @param  list<int>|null  $proveedorIds  null = todos
     * @return Collection<int, Collection<int, array{id:int, fecha:string, vence_el:?string, valor:string, tipo:string, numero_documento:string}>>
     */
    public function comprasCredito(?array $proveedorIds, CarbonInterface $corte): Collection
    {
        return $this->ingresosQueCuentan($proveedorIds, $corte)
            ->where('ingresos.forma_pago', FormaPago::Credito->value)
            ->get(['ingresos.id', 'ingresos.proveedor_id', 'ingresos.tipo', 'ingresos.numero_documento', 'ingresos.fecha', 'ingresos.vence_el', 'ingresos.total'])
            ->groupBy('proveedor_id')
            ->map(fn (Collection $ingresos) => $ingresos->map(fn ($i) => [
                'id' => (int) $i->id,
                'fecha' => $i->fecha,
                'vence_el' => $i->vence_el,
                'valor' => (string) $i->total,
                'tipo' => $i->tipo,
                'numero_documento' => $i->numero_documento,
            ])->values());
    }

    /**
     * RF-18 / RF-19: ingresos confirmados hasta el corte que cuentan como compra, de contado o a
     * crédito. Una remisión cubierta por una factura confirmada hasta el corte queda fuera; si la
     * factura sigue en borrador (o se confirmó después del corte), la remisión cuenta.
     *
     * @param  list<int>|null  $proveedorIds  null = todos
     */
    public function ingresosQueCuentan(?array $proveedorIds, CarbonInterface $corte): Builder
    {
        $hasta = Carbon::instance($corte)->endOfDay();

        return DB::table('ingresos')
            ->leftJoin('ingresos as facturas', 'facturas.id', '=', 'ingresos.factura_id')
            ->where('ingresos.estado', EstadoIngreso::Confirmado->value)
            ->where('ingresos.confirmado_at', '<=', $hasta)
            ->whereNotNull('ingresos.total')
            ->when($proveedorIds !== null, fn ($q) => $q->whereIn('ingresos.proveedor_id', $proveedorIds))
            ->where(fn ($q) => $q
                ->whereNull('ingresos.factura_id')
                ->orWhere('facturas.estado', '!=', EstadoIngreso::Confirmado->value)
                ->orWhere('facturas.confirmado_at', '>', $hasta));
    }

    /**
     * Pagos hasta el corte que no estaban anulados en el corte, sumados por proveedor.
     *
     * @param  list<int>|null  $proveedorIds  null = todos
     * @return array<int, string>
     */
    public function pagado(?array $proveedorIds, CarbonInterface $corte): array
    {
        return $this->movimientosVigentes('pagos_proveedores', 'pago_anulaciones', 'pago_id', 'proveedor_id', $proveedorIds, $corte);
    }

    public function saldoCliente(Cliente $cliente, ?CarbonInterface $corte = null): Antiguedad
    {
        $corte ??= Carbon::today();

        return Antiguedad::calcular(
            $this->ventasCredito([$cliente->id], $corte)->get($cliente->id, collect()),
            $this->abonado([$cliente->id], $corte)[$cliente->id] ?? '0',
            $corte,
        );
    }

    /**
     * Valor neto de cada venta a crédito cerrada hasta el corte, agrupado por cliente.
     *
     * @param  list<int>|null  $clienteIds  null = todos
     * @return Collection<int, Collection<int, array{id:int, fecha:string, vence_el:?string, valor:string}>>
     */
    public function ventasCredito(?array $clienteIds, CarbonInterface $corte): Collection
    {
        $hasta = Carbon::instance($corte)->endOfDay();

        return DB::table('venta_items')
            ->join('ventas', 'ventas.id', '=', 'venta_items.venta_id')
            ->leftJoin('reverso_items', 'reverso_items.venta_item_id', '=', 'venta_items.id')
            ->leftJoin('reversos', 'reversos.id', '=', 'reverso_items.reverso_id')
            ->where('ventas.forma_pago', FormaPago::Credito->value)
            ->where('ventas.estado', EstadoVenta::Cerrada->value)
            ->where('ventas.cerrada_at', '<=', $hasta)
            ->when($clienteIds !== null, fn ($q) => $q->whereIn('ventas.cliente_id', $clienteIds))
            ->whereNotNull('venta_items.precio_unitario')
            ->where(fn ($q) => $q->whereNull('reverso_items.id')->orWhere('reversos.created_at', '>', $hasta))
            ->groupBy('ventas.id', 'ventas.cliente_id', 'ventas.cerrada_at', 'ventas.vence_el')
            ->select(
                'ventas.id',
                'ventas.cliente_id',
                'ventas.cerrada_at',
                'ventas.vence_el',
                DB::raw('SUM(venta_items.precio_unitario) as valor'),
            )
            ->get()
            ->groupBy('cliente_id')
            ->map(fn (Collection $ventas) => $ventas->map(fn ($v) => [
                'id' => (int) $v->id,
                'fecha' => Carbon::parse($v->cerrada_at)->toDateString(),
                'vence_el' => $v->vence_el,
                'valor' => (string) $v->valor,
            ])->values());
    }

    /**
     * Abonos hasta el corte que no estaban anulados en el corte, sumados por cliente.
     *
     * @param  list<int>|null  $clienteIds  null = todos
     * @return array<int, string>
     */
    public function abonado(?array $clienteIds, CarbonInterface $corte): array
    {
        return $this->movimientosVigentes('abonos_clientes', 'abono_anulaciones', 'abono_id', 'cliente_id', $clienteIds, $corte);
    }

    /**
     * Suma por titular de los abonos o pagos con fecha hasta el corte, sin los anulados hasta el
     * corte (uno anulado después todavía contaba ese día).
     *
     * @param  list<int>|null  $titularIds
     * @return array<int, string>
     */
    private function movimientosVigentes(string $tabla, string $anulaciones, string $llave, string $titular, ?array $titularIds, CarbonInterface $corte): array
    {
        $hasta = Carbon::instance($corte)->endOfDay();

        return DB::table($tabla)
            ->leftJoin($anulaciones, fn ($j) => $j
                ->on("{$anulaciones}.{$llave}", '=', "{$tabla}.id")
                ->where("{$anulaciones}.created_at", '<=', $hasta))
            ->whereNull("{$anulaciones}.id")
            ->where("{$tabla}.fecha", '<=', $hasta->toDateString())
            ->when($titularIds !== null, fn ($q) => $q->whereIn("{$tabla}.{$titular}", $titularIds))
            ->groupBy("{$tabla}.{$titular}")
            ->select("{$tabla}.{$titular} as titular", DB::raw("SUM({$tabla}.valor) as valor"))
            ->pluck('valor', 'titular')
            ->map(fn ($v) => (string) $v)
            ->all();
    }
}
