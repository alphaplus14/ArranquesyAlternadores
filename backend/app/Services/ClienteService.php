<?php

namespace App\Services;

use App\Models\Cliente;
use Illuminate\Support\Collection;

/**
 * RF-13 / RF-14 (S-72): lectura para el selector de cliente de la venta a crédito. Solo consulta:
 * no cambia reglas de crédito, cartera ni auditoría. Un cliente inactivo nunca sale aquí.
 */
class ClienteService
{
    /** Tope de resultados: por este camino nadie trae el listado completo. */
    public const LIMITE_BUSQUEDA = 8;

    /** Caracteres mínimos de la búsqueda (con menos, 422). */
    public const MIN_CARACTERES = 2;

    /**
     * Búsqueda rápida de clientes activos: nombre que contiene el texto (la intercalación de MySQL,
     * utf8mb4_unicode_ci, ya ignora mayúsculas y tildes) o documento que empieza por los dígitos
     * escritos (solo si lo escrito son dígitos, con espacios, puntos o guiones opcionales).
     * Primero el documento exacto, luego los nombres que empiezan por el texto, luego el resto.
     *
     * @return Collection<int, Cliente>
     */
    public function buscar(string $texto, int $limite = self::LIMITE_BUSQUEDA): Collection
    {
        $texto = trim($texto);
        if ($texto === '') {
            return collect();
        }

        $comodin = addcslashes($texto, '\\%_');
        $digitos = preg_match('/^[\d\s.\-]+$/', $texto) ? preg_replace('/\D/', '', $texto) : '';

        return Cliente::query()
            ->where('activo', true)
            ->where(fn ($w) => $w
                ->where('nombre', 'like', "%{$comodin}%")
                ->when($digitos !== '', fn ($o) => $o->orWhere('documento', 'like', "{$digitos}%")))
            ->orderByRaw(
                'CASE WHEN documento = ? THEN 0 WHEN nombre LIKE ? THEN 1 ELSE 2 END',
                [$digitos !== '' ? $digitos : $texto, "{$comodin}%"],
            )
            ->orderBy('nombre')
            ->orderBy('id')
            ->limit($this->acotar($limite))
            ->get();
    }

    private function acotar(int $limite): int
    {
        return min(max($limite, 1), self::LIMITE_BUSQUEDA);
    }
}
