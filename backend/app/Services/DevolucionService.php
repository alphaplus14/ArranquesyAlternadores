<?php

namespace App\Services;

use App\Enums\EstadoUnidad;
use App\Enums\EstadoVenta;
use App\Enums\FormaPago;
use App\Enums\MetodoCaptura;
use App\Enums\TipoReverso;
use App\Exceptions\ReglaInventarioException;
use App\Models\Cliente;
use App\Models\Unidad;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RF-07 / S-27: logística (y admin) registra la devolución de un cliente. Es el mismo reverso tipo
 * Devolución de mostrador (ReversoService), así el saldo del cliente sigue siendo neto de reversos
 * (RF-15). Solo aplica a ventas cerradas; en una venta abierta, mostrador anula el ítem.
 *
 * S-34: con la etiqueta del local se encuentra la venta de esa unidad, como siempre. Con el código
 * del proveedor o del fabricante NO se elige la venta automáticamente (cambia el saldo de un cliente
 * concreto, regla 14): se listan las ventas cerradas candidatas y quien registra elige el ítem.
 */
class DevolucionService
{
    /** Máximo de ventas candidatas por código de proveedor. */
    public const MAX_CANDIDATAS = 20;

    public function __construct(
        private readonly ReversoService $reversos,
        private readonly ResolverCodigoService $resolver,
        private readonly CarteraService $cartera,
    ) {}

    /**
     * Consulta previa. Con una etiqueta: el ítem vendido de esa unidad (VentaItem). Con un código de
     * proveedor o fabricante: ['tipo' => 'elegir_venta', ...] con las ventas candidatas.
     *
     * @return VentaItem|array<string, mixed>
     */
    public function consultar(string $codigo, ?int $ventaId = null): VentaItem|array
    {
        $codigo = trim($codigo);
        $unidad = Unidad::query()->where('codigo_barras', $codigo)->first(['id', 'codigo_barras', 'estado']);

        return $unidad ? $this->itemDeUnidad($unidad) : $this->candidatas($codigo, $ventaId);
    }

    /** S-38: motivo por defecto de una devolución registrada por logística o admin. */
    public const MOTIVO_POR_DEFECTO = 'Devolución del cliente';

    /**
     * S-38: el motivo es opcional en este flujo (relaja la regla 8 solo aquí; mostrador sigue
     * exigiéndolo). Siempre se guarda uno: el elegido o "Devolución del cliente", y si hay nota,
     * "{motivo} — {nota}".
     */
    public static function motivo(?string $motivo, ?string $nota): string
    {
        $motivo = trim((string) $motivo) ?: self::MOTIVO_POR_DEFECTO;
        $nota = trim((string) $nota);

        return mb_substr($nota === '' ? $motivo : "{$motivo} — {$nota}", 0, 500);
    }

    /**
     * Registra la devolución. Tres formas:
     * - $datos['venta_id'] + referencia_id + precio_unitario + cantidad (S-38): un grupo elegido de la
     *   lista; se reversan los `cantidad` ítems sin reverso de menor id del grupo.
     * - $datos['venta_item_id'] + codigo (S-34): un ítem elegido de la lista.
     * - $datos['codigo'] = etiqueta del local: esa unidad (cantidad 1).
     * Todo va en UN reverso tipo Devolución (ReversoService bloquea la venta y los ítems y revalida).
     *
     * @param  array{codigo?: string|null, venta_item_id?: int|null, venta_id?: int|null, referencia_id?: int|null, precio_unitario?: string|int|float|null, cantidad?: int|null, motivo?: string|null, nota?: string|null}  $datos
     * @return array{items: Collection<int, VentaItem>, saldo_antes: ?string, saldo_despues: ?string}
     */
    public function registrar(array $datos, MetodoCaptura $metodo, User $usuario): array
    {
        $codigo = trim((string) ($datos['codigo'] ?? ''));
        $items = match (true) {
            ($datos['venta_id'] ?? null) !== null => $this->itemsDelGrupo($datos),
            ($datos['venta_item_id'] ?? null) !== null => collect([$this->itemElegido($codigo, (int) $datos['venta_item_id'])]),
            default => collect([$this->itemPorEtiqueta($codigo)]),
        };

        $venta = $items->first()->venta;
        $cliente = $venta->esCredito() ? $venta->cliente : null;
        $antes = $cliente ? $this->cartera->saldoDe($cliente) : null;

        $this->reversos->reversar($venta, TipoReverso::Devolucion, self::motivo($datos['motivo'] ?? null, $datos['nota'] ?? null), $items->pluck('id')->all(), $usuario, $metodo);

        return [
            'items' => $this->cargar(VentaItem::query()->whereKey($items->pluck('id')))->orderBy('id')->get(),
            'saldo_antes' => $antes,
            'saldo_despues' => $cliente ? $this->cartera->saldoDe($cliente) : null,
        ];
    }

    /**
     * S-38: los `cantidad` ítems sin reverso de menor id de (venta, referencia, precio). La venta debe
     * estar cerrada y la cantidad entre 1 y lo disponible del grupo.
     *
     * @return Collection<int, VentaItem>
     */
    private function itemsDelGrupo(array $datos): Collection
    {
        $ventaId = (int) $datos['venta_id'];
        $venta = Venta::query()->with('cliente:id,nombre,documento')->find($ventaId)
            ?? throw new ReglaInventarioException("No existe la venta #{$ventaId}.", 404);

        if ($venta->estaAbierta()) {
            throw new ReglaInventarioException("La venta #{$ventaId} está abierta: anule el ítem en la venta.", 422);
        }

        $ids = VentaItem::query()
            ->join('unidades as u', 'u.id', '=', 'venta_items.unidad_id')
            ->leftJoin('reverso_items as ri', 'ri.venta_item_id', '=', 'venta_items.id')
            ->where('venta_items.venta_id', $ventaId)
            ->where('u.referencia_id', (int) ($datos['referencia_id'] ?? 0))
            ->where('venta_items.precio_unitario', $datos['precio_unitario'] ?? null)
            ->whereNull('ri.id')
            ->orderBy('venta_items.id')
            ->pluck('venta_items.id');

        if ($ids->isEmpty()) {
            throw new ReglaInventarioException("La venta #{$ventaId} no tiene unidades de esa referencia a ese precio sin devolver.", 422);
        }

        $cantidad = (int) ($datos['cantidad'] ?? 1);
        if ($cantidad < 1 || $cantidad > $ids->count()) {
            throw new ReglaInventarioException(
                $ids->count() === 1
                    ? 'La cantidad debe ser 1: solo queda 1 unidad sin devolver en ese grupo.'
                    : "La cantidad debe estar entre 1 y {$ids->count()} (las unidades sin devolver de ese grupo).",
                422,
                ['campo' => 'cantidad', 'disponibles' => $ids->count()],
            );
        }

        return $this->cargar(VentaItem::query()->whereKey($ids->take($cantidad)))->orderBy('id')->get();
    }

    private function itemPorEtiqueta(string $codigo): VentaItem
    {
        $unidad = Unidad::query()->where('codigo_barras', $codigo)->first(['id', 'codigo_barras', 'estado']);

        if ($unidad) {
            return $this->itemDeUnidad($unidad);
        }

        // Es un código de proveedor: hay que elegir la venta (nunca se adivina).
        $this->resolver->coincidencias($codigo, 1)->isNotEmpty()
            ? throw new ReglaInventarioException("El código {$codigo} es de una referencia: elija la venta que devuelve.", 422)
            : throw $this->noExiste($codigo);
    }

    /** S-34: el ítem elegido de la lista; debe ser de la unidad o de una referencia con ese código. */
    private function itemElegido(string $codigo, int $ventaItemId): VentaItem
    {
        $item = $this->cargar(VentaItem::query()->whereKey($ventaItemId))->first()
            ?? throw new ReglaInventarioException("No existe el ítem de venta #{$ventaItemId}.", 404);

        $delCodigo = $item->unidad->codigo_barras === $codigo
            || $this->resolver->coincidencias($codigo, ResolverCodigoService::MAX_OPCIONES * 4)->contains(fn ($r) => $r->id === $item->unidad->referencia_id);

        if (! $delCodigo) {
            throw new ReglaInventarioException("El ítem elegido no corresponde al código {$codigo}.", 422);
        }

        $unidad = $item->unidad->codigo_barras;

        if ($item->reversoItem) {
            throw new ReglaInventarioException("La unidad {$unidad} de la venta #{$item->venta_id} ya fue devuelta o anulada.", 422);
        }

        if ($item->venta->estaAbierta()) {
            throw new ReglaInventarioException("La unidad {$unidad} está en la venta abierta #{$item->venta_id}: anule el ítem en la venta.", 422);
        }

        return $item;
    }

    private function itemDeUnidad(Unidad $unidad): VentaItem
    {
        $codigo = $unidad->codigo_barras;

        if ($unidad->estado === EstadoUnidad::Retirada) {
            throw new ReglaInventarioException("La unidad {$codigo} está retirada del inventario; no está vendida.", 422);
        }

        if ($unidad->estado === EstadoUnidad::Disponible) {
            $yaReversada = VentaItem::query()->where('unidad_id', $unidad->id)->whereHas('reversoItem')->exists();

            throw new ReglaInventarioException($yaReversada
                ? "La unidad {$codigo} ya fue devuelta o anulada: está en inventario."
                : "La unidad {$codigo} no se ha vendido: está en inventario.", 422);
        }

        // Una unidad devuelta puede venderse otra vez: el ítem vigente es el último sin reverso.
        $item = $this->cargar(VentaItem::query()->where('unidad_id', $unidad->id)->whereDoesntHave('reversoItem'))
            ->orderByDesc('id')
            ->first();

        if (! $item) {
            throw new ReglaInventarioException("La unidad {$codigo} ya fue reversada.", 422);
        }

        if ($item->venta->estaAbierta()) {
            throw new ReglaInventarioException(
                "La unidad {$codigo} está en la venta abierta #{$item->venta->id}: anule el ítem en la venta.",
                422,
            );
        }

        return $item;
    }

    /**
     * S-34 / S-38: con un código de proveedor o fabricante, las ventas CERRADAS con unidades de esa
     * referencia sin reverso, AGRUPADAS por (venta, referencia, precio): cada grupo dice cuántas
     * siguen sin devolver. Del cierre más reciente al más antiguo, hasta 20 grupos. Una sola consulta
     * con JOIN y GROUP BY (sin N+1); usa el índice (referencia_id, estado) de unidades: un ítem
     * vigente tiene su unidad vendida. Las ventas a crédito traen el saldo actual del cliente
     * (una consulta por cliente distinto, como mucho 20).
     *
     * @return array<string, mixed>
     */
    private function candidatas(string $codigo, ?int $ventaId): array
    {
        $referencias = $this->resolver->coincidencias($codigo, 50);

        if ($referencias->isEmpty()) {
            throw $this->noExiste($codigo);
        }

        $grupos = VentaItem::query()
            ->join('unidades as u', 'u.id', '=', 'venta_items.unidad_id')
            ->join('ventas as v', 'v.id', '=', 'venta_items.venta_id')
            ->leftJoin('reverso_items as ri', 'ri.venta_item_id', '=', 'venta_items.id')
            ->leftJoin('clientes as c', 'c.id', '=', 'v.cliente_id')
            ->whereIn('u.referencia_id', $referencias->pluck('id'))
            ->where('u.estado', EstadoUnidad::Vendida->value)
            ->where('v.estado', EstadoVenta::Cerrada->value)
            ->whereNull('ri.id')
            ->when($ventaId, fn ($q, $id) => $q->where('v.id', $id))
            ->groupBy('v.id', 'v.cerrada_at', 'v.forma_pago', 'c.id', 'c.nombre', 'c.documento', 'u.referencia_id', 'venta_items.precio_unitario')
            ->orderByDesc('v.cerrada_at')
            ->orderByDesc('v.id')
            ->orderBy('u.referencia_id')
            ->orderByDesc('venta_items.precio_unitario')
            ->limit(self::MAX_CANDIDATAS)
            ->get([
                'v.id as venta_id', 'v.cerrada_at', 'v.forma_pago', 'c.id as cliente_id', 'c.nombre as cliente_nombre', 'c.documento as cliente_documento',
                'u.referencia_id', 'venta_items.precio_unitario', DB::raw('COUNT(*) as cantidad_disponible'), DB::raw('MIN(venta_items.id) as primer_item'),
                // En el orden en que se devuelven (menor id primero), para mostrarlas antes de registrar.
                DB::raw("GROUP_CONCAT(u.codigo_barras ORDER BY venta_items.id SEPARATOR ',') as codigos"),
            ]);

        if ($grupos->isEmpty() && $ventaId === null) {
            $nombres = $referencias->pluck('codigo_referencia')->unique()->implode(', ');
            throw new ReglaInventarioException("No hay ventas cerradas sin devolver de la referencia {$nombres}.", 422);
        }

        $porId = $referencias->keyBy('id');
        $saldos = Cliente::query()
            ->whereKey($grupos->where('forma_pago', FormaPago::Credito->value)->pluck('cliente_id')->filter()->unique())
            ->get()
            ->mapWithKeys(fn (Cliente $c) => [$c->id => $this->cartera->saldoDe($c)]);

        return [
            'tipo' => 'elegir_venta',
            'codigo' => $codigo,
            'ventas' => $grupos->map(fn ($g) => [
                'venta_id' => (int) $g->venta_id,
                'cerrada_at' => self::instante($g->cerrada_at),
                'forma_pago' => $g->forma_pago,
                'cliente' => $g->cliente_id ? ['id' => (int) $g->cliente_id, 'nombre' => $g->cliente_nombre, 'documento' => $g->cliente_documento] : null,
                'saldo_actual' => $g->cliente_id ? ($saldos[(int) $g->cliente_id] ?? null) : null,
                'referencia' => [
                    'id' => (int) $g->referencia_id,
                    'codigo_referencia' => $porId[$g->referencia_id]->codigo_referencia,
                    'descripcion' => $porId[$g->referencia_id]->descripcion,
                    'calidad' => $porId[$g->referencia_id]->calidad,
                    'proveedor' => $porId[$g->referencia_id]->proveedor?->nombre,
                ],
                'precio_unitario' => $g->precio_unitario,
                'cantidad_disponible' => (int) $g->cantidad_disponible,
                'codigos' => explode(',', (string) $g->codigos),
                // S-34: compatibilidad con quien todavía elige un solo ítem.
                'venta_item_id' => (int) $g->primer_item,
            ])->values()->all(),
        ];
    }

    /** Instante de la base (UTC) como ISO 8601 con zona: el frontend lo muestra en hora de Bogotá. */
    private static function instante(?string $valor): ?string
    {
        return $valor === null ? null : Carbon::parse($valor, config('app.timezone'))->utc()->toIso8601ZuluString();
    }

    private function noExiste(string $codigo): ReglaInventarioException
    {
        return new ReglaInventarioException("No existe una unidad con el código {$codigo}.", 404);
    }

    private function cargar($consulta)
    {
        return $consulta->with([
            'venta:id,estado,forma_pago,cliente_id,created_at,cerrada_at',
            'venta.cliente:id,nombre,documento',
            'unidad:id,referencia_id,codigo_barras,estado',
            'unidad.referencia:id,codigo_referencia,descripcion,calidad,proveedor_id',
            'unidad.referencia.proveedor:id,nombre',
            'reversoItem.reverso:id,tipo,motivo,usuario_id,created_at',
        ]);
    }
}
