<?php

namespace App\Services;

use App\Models\User;

/**
 * S-70: preferencias de pantalla por usuario. Se guarda solo lo que el usuario cambió (merge con lo
 * ya guardado); null borra la clave. Sin auditoría ni movimientos: no son datos del negocio.
 */
class PreferenciasService
{
    public const TAMANOS_LETRA = ['normal', 'grande', 'muy_grande'];

    public const DENSIDADES = ['comoda', 'compacta'];

    /** Las 6 paletas del frontend (src/preferencias/paletas.json). */
    public const ACENTOS = ['azul', 'verde', 'morado', 'naranja', 'vino', 'grafito'];

    public const FILAS_POR_PAGINA = [25, 50, 100];

    public const CLAVES = ['tamano_letra', 'densidad', 'acento', 'filas_por_pagina', 'menu_plegado'];

    /**
     * @param  array<string, mixed>  $cambios  ya validados
     * @return array<string, mixed>
     */
    public function actualizar(User $usuario, array $cambios): array
    {
        $preferencias = $usuario->preferencias ?? [];

        foreach ($cambios as $clave => $valor) {
            if ($valor === null) {
                unset($preferencias[$clave]);

                continue;
            }

            $preferencias[$clave] = match ($clave) {
                'filas_por_pagina' => (int) $valor,
                'menu_plegado' => (bool) filter_var($valor, FILTER_VALIDATE_BOOLEAN),
                default => (string) $valor,
            };
        }

        ksort($preferencias);
        $usuario->preferencias = $preferencias === [] ? null : $preferencias;
        // No toca updated_at: cambiar la pantalla no es editar al usuario.
        $usuario->timestamps = false;
        $usuario->save();
        $usuario->timestamps = true;

        return $preferencias;
    }

    /** Lo que viaja en /me: un objeto ({} si no hay o si el rol no las tiene). */
    public static function paraRespuesta(User $usuario): object
    {
        return (object) ($usuario->admitePreferencias() ? ($usuario->preferencias ?? []) : []);
    }
}
