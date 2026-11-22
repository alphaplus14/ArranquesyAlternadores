<?php

namespace App\Services;

use App\Enums\EstadoVenta;
use App\Enums\FormaPago;
use App\Models\Cliente;
use App\Support\Antiguedad;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RF-15 / RF-18: saldos de clientes a una fecha de corte. Solo lectura: no toca existencias.
 *
 * Saldo del cliente = ventas a crédito cerradas (S-09), netas de los ítems reversados hasta el
 * corte, menos los abonos no anulados hasta el corte. El valor de cada venta es la suma de sus
 * precios (S-24); los ítems sin precio (ventas anteriores a RF-14) no cuentan.
 */
class CarteraService
{
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
        $hasta = Carbon::instance($corte)->endOfDay();

        return DB::table('abonos_clientes')
            ->leftJoin('abono_anulaciones', fn ($j) => $j
                ->on('abono_anulaciones.abono_id', '=', 'abonos_clientes.id')
                ->where('abono_anulaciones.created_at', '<=', $hasta))
            ->whereNull('abono_anulaciones.id')
            ->where('abonos_clientes.fecha', '<=', $hasta->toDateString())
            ->when($clienteIds !== null, fn ($q) => $q->whereIn('abonos_clientes.cliente_id', $clienteIds))
            ->groupBy('abonos_clientes.cliente_id')
            ->select('abonos_clientes.cliente_id', DB::raw('SUM(abonos_clientes.valor) as valor'))
            ->pluck('valor', 'cliente_id')
            ->map(fn ($v) => (string) $v)
            ->all();
    }
}
