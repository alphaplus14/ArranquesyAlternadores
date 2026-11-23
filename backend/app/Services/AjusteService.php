<?php

namespace App\Services;

use App\Enums\MetodoCaptura;
use App\Enums\MotivoAjuste;
use App\Enums\TipoAjuste;
use App\Exceptions\ReglaInventarioException;
use App\Models\AjusteInventario;
use App\Models\AjusteItem;
use App\Models\Unidad;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RF-21 (S-27): logística y admin sacan unidades del inventario (baja) y corrigen una baja hecha
 * por error (reintegro). Cada código es el código local de una unidad física (regla 3, RF-03).
 *
 * Un lote es atómico: entran todas sus unidades o ninguna, y el error nombra el código que falló.
 * Nada se borra: el ajuste, sus ítems y los movimientos son inmutables. Garantía es solo un motivo
 * (sin reclamos ni reposición) y una devolución a proveedor no toca el saldo del proveedor (RF-17).
 */
class AjusteService
{
    public const MAX_UNIDADES = 200;

    private const POR_PAGINA = 20;

    public function __construct(private readonly InventarioService $inventario) {}

    /** @param list<array{codigo: string, metodo_captura: MetodoCaptura|string}> $codigos */
    public function retirar(MotivoAjuste $motivo, ?string $nota, array $codigos, User $usuario): AjusteInventario
    {
        return $this->ajustar(TipoAjuste::Baja, $motivo, $nota, $codigos, $usuario);
    }

    /** @param list<array{codigo: string, metodo_captura: MetodoCaptura|string}> $codigos */
    public function reintegrar(MotivoAjuste $motivo, ?string $nota, array $codigos, User $usuario): AjusteInventario
    {
        return $this->ajustar(TipoAjuste::Reintegro, $motivo, $nota, $codigos, $usuario);
    }

    /**
     * Consulta previa de la pantalla al escanear un código para el lote. No escribe: la regla se
     * vuelve a validar, con la unidad bloqueada, al registrar el ajuste.
     */
    public function verificar(TipoAjuste $tipo, string $codigo): Unidad
    {
        $codigo = trim($codigo);

        $unidad = Unidad::query()
            ->where('codigo_barras', $codigo)
            ->with('referencia:id,codigo_referencia,descripcion,calidad,activo,proveedor_id', 'referencia.proveedor:id,nombre')
            ->first(['id', 'referencia_id', 'codigo_barras', 'estado']);

        if (! $unidad) {
            throw $this->noExiste($codigo);
        }

        $this->inventario->exigirEstadoPara($tipo, $unidad);
        $this->exigirReferenciaActiva($tipo, $unidad);

        return $unidad;
    }

    /**
     * Historial paginado, del más reciente al más antiguo. Cada unidad trae su estado actual y si
     * se puede reintegrar desde esta baja: está retirada y esta es su última baja.
     *
     * @param  array{tipo?: string|null, motivo?: string|null, desde?: string|null, hasta?: string|null, usuario_id?: int|string|null}  $filtros
     */
    public function historial(array $filtros): LengthAwarePaginator
    {
        $pagina = AjusteInventario::query()
            ->with([
                'usuario:id,name',
                'items' => fn ($q) => $q->orderBy('id'),
                'items.unidad:id,referencia_id,codigo_barras,estado',
                'items.unidad.referencia:id,codigo_referencia,descripcion,calidad,proveedor_id',
                'items.unidad.referencia.proveedor:id,nombre',
            ])
            ->withCount('items')
            ->when($filtros['tipo'] ?? null, fn ($q, $v) => $q->where('tipo', $v))
            ->when($filtros['motivo'] ?? null, fn ($q, $v) => $q->where('motivo', $v))
            ->when($filtros['desde'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', Carbon::parse($v)->startOfDay()))
            ->when($filtros['hasta'] ?? null, fn ($q, $v) => $q->where('created_at', '<', Carbon::parse($v)->addDay()->startOfDay()))
            ->when($filtros['usuario_id'] ?? null, fn ($q, $v) => $q->where('usuario_id', $v))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::POR_PAGINA);

        $this->marcarReintegrables($pagina->getCollection());

        return $pagina;
    }

    /** @param list<array{codigo: string, metodo_captura: MetodoCaptura|string}> $codigos */
    private function ajustar(TipoAjuste $tipo, MotivoAjuste $motivo, ?string $nota, array $codigos, User $usuario): AjusteInventario
    {
        if (! in_array($motivo, MotivoAjuste::para($tipo), true)) {
            throw new ReglaInventarioException('El motivo no corresponde a este tipo de ajuste.', 422);
        }

        $nota = trim((string) $nota) === '' ? null : trim((string) $nota);

        if ($motivo->exigeNota() && $nota === null) {
            throw new ReglaInventarioException('Escriba una nota: el motivo "Otro" la exige.', 422);
        }

        $lote = $this->normalizarLote($codigos);

        return DB::transaction(function () use ($tipo, $motivo, $nota, $lote, $usuario) {
            // Orden fijo por id: dos lotes simultáneos bloquean las mismas unidades en el mismo orden.
            $unidades = Unidad::query()
                ->whereIn('codigo_barras', array_keys($lote))
                ->with('referencia:id,codigo_referencia,activo')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $porCodigo = $unidades->keyBy('codigo_barras');

            // Se revisa en el orden del lote para nombrar el primer código que falla.
            foreach (array_keys($lote) as $codigo) {
                $unidad = $porCodigo->get($codigo) ?? throw $this->noExiste($codigo);
                $this->inventario->exigirEstadoPara($tipo, $unidad);
                $this->exigirReferenciaActiva($tipo, $unidad);
            }

            $ajuste = AjusteInventario::create([
                'tipo' => $tipo,
                'motivo' => $motivo,
                'nota' => $nota,
                'usuario_id' => $usuario->id,
            ]);

            foreach ($unidades as $unidad) {
                $metodo = $lote[$unidad->codigo_barras];

                $tipo === TipoAjuste::Baja
                    ? $this->inventario->retirar($unidad, $ajuste, $usuario->id, $metodo)
                    : $this->inventario->reintegrar($unidad, $ajuste, $usuario->id, $metodo);
            }

            AjusteItem::insert($unidades->map(fn (Unidad $u) => [
                'ajuste_id' => $ajuste->id,
                'unidad_id' => $u->id,
                'metodo_captura' => $lote[$u->codigo_barras]->value,
            ])->all());

            return $ajuste;
        });
    }

    /**
     * @param  list<array{codigo: string, metodo_captura: MetodoCaptura|string}>  $codigos
     * @return array<string, MetodoCaptura> código => método, en el orden del lote
     */
    private function normalizarLote(array $codigos): array
    {
        if ($codigos === []) {
            throw new ReglaInventarioException('El lote no tiene unidades.', 422);
        }

        if (count($codigos) > self::MAX_UNIDADES) {
            throw new ReglaInventarioException('Un lote admite hasta '.self::MAX_UNIDADES.' unidades.', 422);
        }

        $lote = [];

        foreach ($codigos as $fila) {
            $codigo = trim((string) $fila['codigo']);

            if (isset($lote[$codigo])) {
                throw new ReglaInventarioException("El código {$codigo} está repetido en el lote.", 422, ['codigo' => $codigo]);
            }

            $metodo = $fila['metodo_captura'];
            $lote[$codigo] = $metodo instanceof MetodoCaptura ? $metodo : MetodoCaptura::from($metodo);
        }

        return $lote;
    }

    /**
     * S-26: una referencia inactiva no tiene unidades disponibles; un reintegro no la revive a
     * escondidas. Primero se reactiva la referencia.
     */
    private function exigirReferenciaActiva(TipoAjuste $tipo, Unidad $unidad): void
    {
        if ($tipo === TipoAjuste::Reintegro && ! $unidad->referencia->activo) {
            throw new ReglaInventarioException(
                "La referencia {$unidad->referencia->codigo_referencia} de la unidad {$unidad->codigo_barras} está inactiva; reactívela para reintegrar la unidad.",
                422,
                ['codigo' => $unidad->codigo_barras],
            );
        }
    }

    private function noExiste(string $codigo): ReglaInventarioException
    {
        return new ReglaInventarioException("No existe una unidad con el código {$codigo}.", 422, ['codigo' => $codigo]);
    }

    /** @param Collection<int, AjusteInventario> $ajustes */
    private function marcarReintegrables($ajustes): void
    {
        $unidadIds = $ajustes->where('tipo', TipoAjuste::Baja)->flatMap->items->pluck('unidad_id')->unique()->values();

        // Última baja de cada unidad de la página, en una sola consulta (índice ajuste_items.unidad_id).
        $ultimaBaja = $unidadIds->isEmpty() ? collect() : AjusteItem::query()
            ->join('ajustes_inventario as a', 'a.id', '=', 'ajuste_items.ajuste_id')
            ->where('a.tipo', TipoAjuste::Baja->value)
            ->whereIn('ajuste_items.unidad_id', $unidadIds)
            ->groupBy('ajuste_items.unidad_id')
            ->selectRaw('ajuste_items.unidad_id as unidad, MAX(ajuste_items.ajuste_id) as ultima')
            ->pluck('ultima', 'unidad');

        foreach ($ajustes as $ajuste) {
            foreach ($ajuste->items as $item) {
                $item->reintegrable = $ajuste->tipo === TipoAjuste::Baja
                    && $item->unidad->estaRetirada()
                    && (int) ($ultimaBaja[$item->unidad_id] ?? 0) === $ajuste->id;
            }
        }
    }
}
