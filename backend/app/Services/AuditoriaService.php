<?php

namespace App\Services;

use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\CategoriaCaja;
use App\Models\Cliente;
use App\Models\CodigoUniversal;
use App\Models\CuentaPago;
use App\Models\Empleado;
use App\Models\Ingreso;
use App\Models\Proveedor;
use App\Models\Referencia;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * RF-11: historial de ediciones, solo lectura. Además de las filas tal cual se guardaron
 * (valor_anterior y valor_nuevo intactos), devuelve `nombres`: para cada campo de id que aparece
 * en la página (en filas de un campo o dentro de la foto JSON de una creación o borrado), el
 * nombre legible de cada id. Se resuelven en lote: una consulta por tipo de registro, con whereIn
 * sobre los ids de la página, sin importar cuántas filas haya.
 */
class AuditoriaService
{
    /** Campo de id => modelo al que apunta. */
    private const CAMPOS_ID = [
        'proveedor_id' => Proveedor::class,
        'categoria_id' => Categoria::class,
        'codigo_universal_id' => CodigoUniversal::class,
        'cliente_id' => Cliente::class,
        'referencia_id' => Referencia::class,
        'ingreso_id' => Ingreso::class,
        'factura_id' => Ingreso::class,
        'usuario_id' => User::class,
        'confirmado_por' => User::class,
        'cuenta_pago_id' => CuentaPago::class,
        'categoria_caja_id' => CategoriaCaja::class,
        'empleado_id' => Empleado::class,
    ];

    /** @return array<string, mixed> La página de Laravel (plana) más `nombres`. */
    public function listar(?string $tabla, ?int $registroId, int $porPagina = 50): array
    {
        $pagina = Auditoria::query()
            ->with('usuario:id,name')
            ->when($tabla, fn ($q, $v) => $q->where('tabla', $v))
            ->when($registroId, fn ($q, $v) => $q->where('registro_id', $v))
            ->orderByDesc('id')
            ->paginate($porPagina); // S-70

        return [...$pagina->toArray(), 'nombres' => $this->nombres($pagina->getCollection())];
    }

    /**
     * @param  Collection<int, Auditoria>  $filas
     * @return array<string, object> campo => {id: nombre}. Un campo pedido cuyo registro ya no
     *                               existe queda sin ese id (la pantalla muestra "#id").
     */
    public function nombres(Collection $filas): array
    {
        /** @var array<string, array<int, true>> $idsPorCampo */
        $idsPorCampo = [];
        $anotar = function (string $campo, mixed $valor) use (&$idsPorCampo) {
            if (! isset(self::CAMPOS_ID[$campo]) || ! is_scalar($valor) || ! ctype_digit((string) $valor) || (int) $valor < 1) {
                return;
            }
            $idsPorCampo[$campo][(int) $valor] = true;
        };

        foreach ($filas as $fila) {
            if ($fila->campo !== '*') {
                $anotar($fila->campo, $fila->valor_anterior);
                $anotar($fila->campo, $fila->valor_nuevo);

                continue;
            }
            foreach ([$fila->valor_anterior, $fila->valor_nuevo] as $foto) {
                $datos = is_string($foto) ? json_decode($foto, true) : null;
                foreach (is_array($datos) ? $datos : [] as $campo => $valor) {
                    $anotar((string) $campo, $valor);
                }
            }
        }

        // Una consulta por modelo (usuario_id y confirmado_por comparten la de users).
        $idsPorModelo = [];
        foreach ($idsPorCampo as $campo => $ids) {
            $idsPorModelo[self::CAMPOS_ID[$campo]] = ($idsPorModelo[self::CAMPOS_ID[$campo]] ?? []) + $ids;
        }
        $nombresPorModelo = [];
        foreach ($idsPorModelo as $modelo => $ids) {
            $nombresPorModelo[$modelo] = $this->nombresDe($modelo, array_keys($ids));
        }

        $resultado = [];
        foreach ($idsPorCampo as $campo => $ids) {
            $todos = $nombresPorModelo[self::CAMPOS_ID[$campo]];
            $resultado[$campo] = (object) array_intersect_key($todos, $ids);
        }

        return $resultado;
    }

    /**
     * @param  class-string  $modelo
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function nombresDe(string $modelo, array $ids): array
    {
        $base = $modelo::query()->whereIn('id', $ids)->toBase();

        return match ($modelo) {
            User::class => $base->pluck('name', 'id')->all(),
            CodigoUniversal::class => $base->pluck('codigo', 'id')->all(),
            Referencia::class => $base->pluck('codigo_referencia', 'id')->all(),
            Ingreso::class => $base->get(['id', 'tipo', 'numero_documento'])
                ->mapWithKeys(fn ($i) => [$i->id => ($i->tipo === 'factura' ? 'Factura' : 'Remisión').' '.$i->numero_documento])
                ->all(),
            default => $base->pluck('nombre', 'id')->all(),
        };
    }
}
