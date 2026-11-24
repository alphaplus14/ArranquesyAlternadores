<?php

namespace App\Services;

use App\Enums\MetodoCaptura;
use App\Enums\MotivoAjuste;
use App\Enums\OrigenCodigo;
use App\Enums\TipoAjuste;
use App\Exceptions\ReglaInventarioException;
use App\Models\AjusteInventario;
use App\Models\AjusteItem;
use App\Models\Unidad;
use App\Models\User;
use App\Support\RangoFechas;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RF-21 (S-27): logística y admin sacan unidades del inventario (baja) y corrigen una baja hecha
 * por error (reintegro). Cada código es el código local de una unidad física (regla 3, RF-03).
 * S-34: en una SALIDA también vale el código del proveedor o del fabricante: el sistema elige la
 * unidad disponible con el criterio de S-31 (ResolverCodigoService), sin repetir unidades del mismo
 * lote. Cada ítem guarda origen_codigo ('etiqueta' | 'referencia'). Los reintegros siguen solo con
 * la etiqueta (se hacen sobre las unidades de una salida, desde el historial).
 *
 * Un lote es atómico: entran todas sus unidades o ninguna, y el error nombra el código que falló.
 * Nada se borra: el ajuste, sus ítems y los movimientos son inmutables. Garantía es solo un motivo
 * (sin reclamos ni reposición) y una devolución a proveedor no toca el saldo del proveedor (RF-17).
 */
class AjusteService
{
    public const MAX_UNIDADES = 200;

    private const POR_PAGINA = 20;

    public function __construct(
        private readonly InventarioService $inventario,
        private readonly ResolverCodigoService $resolver,
    ) {}

    /** @param list<array{codigo: string, metodo_captura: MetodoCaptura|string, origen_codigo?: string|null, unidad_codigo?: string|null}> $codigos */
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
     * vuelve a validar, con las unidades bloqueadas, al registrar el ajuste.
     *
     * S-34 (solo salidas): si el código no es una etiqueta, se resuelve la referencia por el código del
     * proveedor o del fabricante. S-38: se asignan $cantidad unidades disponibles DISTINTAS que no estén
     * en $excluir (las ya elegidas en el lote); si hay menos, 422 y ninguna. Con una etiqueta la
     * cantidad es siempre 1. $referenciaId resuelve un código que está en varias referencias. Cada
     * unidad devuelta lleva origen_codigo.
     *
     * @param  list<int>  $excluir
     * @return Collection<int, Unidad>
     */
    public function verificar(TipoAjuste $tipo, string $codigo, ?int $referenciaId = null, array $excluir = [], int $cantidad = 1): Collection
    {
        $codigo = trim($codigo);
        $relaciones = ['referencia:id,codigo_referencia,descripcion,calidad,activo,proveedor_id', 'referencia.proveedor:id,nombre'];

        $unidad = Unidad::query()
            ->where('codigo_barras', $codigo)
            ->with($relaciones)
            ->first(['id', 'referencia_id', 'codigo_barras', 'estado']);

        if ($unidad) {
            if ($cantidad > 1) {
                throw new ReglaInventarioException("Cada etiqueta es una unidad: {$codigo} se agrega de a una (cantidad 1).", 422, ['codigo' => $codigo]);
            }
            $this->inventario->exigirEstadoPara($tipo, $unidad);
            $this->exigirReferenciaActiva($tipo, $unidad);
            $unidad->setAttribute('origen_codigo', OrigenCodigo::Etiqueta->value);

            return collect([$unidad]);
        }

        if ($tipo !== TipoAjuste::Baja) {
            throw $this->noExiste($codigo);
        }

        $referencia = $this->resolver->referencia($codigo, $referenciaId, bloquear: false, accion: 'se retira', noExiste: fn () => $this->noExiste($codigo));
        $ids = $this->resolver->unidadesDisponibles($referencia, $cantidad, $excluir)->pluck('id');

        return Unidad::query()->whereKey($ids)->with($relaciones)->orderBy('id')
            ->get(['id', 'referencia_id', 'codigo_barras', 'estado'])
            // Mismo orden en que las eligió el criterio (sin etiqueta primero).
            ->sortBy(fn (Unidad $u) => $ids->search($u->id))->values()
            ->each(fn (Unidad $u) => $u->setAttribute('origen_codigo', OrigenCodigo::Referencia->value));
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
            // Días locales (Bogotá) convertidos a UTC; "hasta" es inclusivo del día completo.
            ->when($filtros['desde'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', RangoFechas::inicio($v)))
            ->when($filtros['hasta'] ?? null, fn ($q, $v) => $q->where('created_at', '<', RangoFechas::finExclusivo($v)))
            ->when($filtros['usuario_id'] ?? null, fn ($q, $v) => $q->where('usuario_id', $v))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::POR_PAGINA);

        $this->marcarReintegrables($pagina->getCollection());

        return $pagina;
    }

    /**
     * Un lote atómico: entran todas sus unidades o ninguna, y el error nombra la unidad o el código
     * que falló, en el orden del lote.
     *
     * Cada fila trae el código leído y, si fue un código de proveedor (S-34), la unidad que asignó la
     * verificación (unidad_codigo). Esa unidad se vuelve a bloquear y validar: si otro usuario la
     * vendió o retiró en medio, el lote falla completo y dice cuál. Una fila de salida con código de
     * proveedor sin unidad_codigo se resuelve aquí mismo, sin repetir unidades del lote.
     *
     * @param  list<array{codigo: string, metodo_captura: MetodoCaptura|string, origen_codigo?: string|null, unidad_codigo?: string|null}>  $codigos
     */
    private function ajustar(TipoAjuste $tipo, MotivoAjuste $motivo, ?string $nota, array $codigos, User $usuario): AjusteInventario
    {
        if (! in_array($motivo, MotivoAjuste::para($tipo), true)) {
            throw new ReglaInventarioException('El motivo no corresponde a este tipo de ajuste.', 422);
        }

        $nota = trim((string) $nota) === '' ? null : trim((string) $nota);

        if ($motivo->exigeNota() && $nota === null) {
            throw new ReglaInventarioException('Escriba una nota: el motivo "Otro" la exige.', 422);
        }

        $lote = $this->normalizarLote($tipo, $codigos);

        return DB::transaction(function () use ($tipo, $motivo, $nota, $lote, $usuario) {
            // Unidades por su etiqueta (la leída o la asignada), bloqueadas en orden fijo por id: dos
            // lotes simultáneos bloquean las mismas unidades en el mismo orden.
            $etiquetas = array_values(array_unique(array_map(fn ($f) => $f['unidad_codigo'] ?? $f['codigo'], $lote)));
            $porCodigo = Unidad::query()
                ->whereIn('codigo_barras', $etiquetas)
                ->with('referencia:id,codigo_referencia,activo')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('codigo_barras');

            // Se resuelve y revisa en el orden del lote para nombrar el primero que falla.
            $elegidas = [];
            foreach ($lote as $fila) {
                if ($fila['unidad_codigo'] !== null) {
                    $unidad = $porCodigo->get($fila['unidad_codigo']) ?? throw $this->noExiste($fila['unidad_codigo']);
                    $this->exigirDelCodigo($unidad, $fila['codigo']);
                    $origen = OrigenCodigo::Referencia;
                } elseif ($unidad = $porCodigo->get($fila['codigo'])) {
                    $origen = OrigenCodigo::Etiqueta;
                } elseif ($tipo === TipoAjuste::Baja) {
                    $referencia = $this->resolver->referencia($fila['codigo'], null, bloquear: false, accion: 'se retira', noExiste: fn () => $this->noExiste($fila['codigo']));
                    // Sin repetir unidades del lote: ni las ya elegidas ni las que vienen por etiqueta o asignadas.
                    $excluir = array_values(array_unique([...array_keys($elegidas), ...$porCodigo->pluck('id')->all()]));
                    $unidad = $this->resolver->unidadDisponible($referencia, $excluir)->load('referencia:id,codigo_referencia,activo');
                    $origen = OrigenCodigo::Referencia;
                } else {
                    throw $this->noExiste($fila['codigo']);
                }

                if (isset($elegidas[$unidad->id])) {
                    throw new ReglaInventarioException("La unidad {$unidad->codigo_barras} está repetida en el lote.", 422, ['codigo' => $unidad->codigo_barras]);
                }

                $this->inventario->exigirEstadoPara($tipo, $unidad);
                $this->exigirReferenciaActiva($tipo, $unidad);
                $elegidas[$unidad->id] = ['unidad' => $unidad, 'metodo' => $fila['metodo'], 'origen' => $origen];
            }

            $ajuste = AjusteInventario::create([
                'tipo' => $tipo,
                'motivo' => $motivo,
                'nota' => $nota,
                'usuario_id' => $usuario->id,
            ]);

            ksort($elegidas);
            foreach ($elegidas as $e) {
                $tipo === TipoAjuste::Baja
                    ? $this->inventario->retirar($e['unidad'], $ajuste, $usuario->id, $e['metodo'])
                    : $this->inventario->reintegrar($e['unidad'], $ajuste, $usuario->id, $e['metodo']);
            }

            AjusteItem::insert(array_map(fn ($e) => [
                'ajuste_id' => $ajuste->id,
                'unidad_id' => $e['unidad']->id,
                'metodo_captura' => $e['metodo']->value,
                'origen_codigo' => $e['origen']->value,
            ], array_values($elegidas)));

            return $ajuste;
        });
    }

    /**
     * S-34: la unidad asignada por la verificación debe ser de una referencia activa con el código
     * leído (no se acepta cualquier unidad con un código de proveedor ajeno).
     */
    private function exigirDelCodigo(Unidad $unidad, string $codigo): void
    {
        $coincide = $this->resolver->coincidencias($codigo, ResolverCodigoService::MAX_OPCIONES * 4)
            ->contains(fn ($r) => $r->id === $unidad->referencia_id);

        if (! $coincide) {
            throw new ReglaInventarioException("La unidad {$unidad->codigo_barras} no corresponde al código {$codigo}.", 422, ['codigo' => $unidad->codigo_barras]);
        }
    }

    /**
     * @param  list<array{codigo: string, metodo_captura: MetodoCaptura|string, origen_codigo?: string|null, unidad_codigo?: string|null}>  $codigos
     * @return list<array{codigo: string, unidad_codigo: string|null, metodo: MetodoCaptura}> en el orden del lote
     */
    private function normalizarLote(TipoAjuste $tipo, array $codigos): array
    {
        if ($codigos === []) {
            throw new ReglaInventarioException('El lote no tiene unidades.', 422);
        }

        if (count($codigos) > self::MAX_UNIDADES) {
            throw new ReglaInventarioException('Un lote admite hasta '.self::MAX_UNIDADES.' unidades.', 422);
        }

        $lote = [];
        $etiquetas = [];

        foreach ($codigos as $fila) {
            $codigo = trim((string) $fila['codigo']);
            $unidadCodigo = trim((string) ($fila['unidad_codigo'] ?? '')) ?: null;

            // Un reintegro solo trabaja con la etiqueta de la unidad.
            if ($tipo === TipoAjuste::Reintegro) {
                $unidadCodigo = null;
            }

            // La misma etiqueta (leída o asignada) no entra dos veces; el mismo código de proveedor sí:
            // cada lectura toma una unidad distinta.
            $porReferencia = $unidadCodigo === null && ($fila['origen_codigo'] ?? null) === OrigenCodigo::Referencia->value;
            $etiqueta = $unidadCodigo ?? $codigo;
            if (! $porReferencia) {
                if (isset($etiquetas[$etiqueta])) {
                    throw new ReglaInventarioException("El código {$etiqueta} está repetido en el lote.", 422, ['codigo' => $etiqueta]);
                }
                $etiquetas[$etiqueta] = true;
            }

            $metodo = $fila['metodo_captura'];
            $lote[] = [
                'codigo' => $codigo,
                'unidad_codigo' => $unidadCodigo,
                'metodo' => $metodo instanceof MetodoCaptura ? $metodo : MetodoCaptura::from($metodo),
            ];
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
