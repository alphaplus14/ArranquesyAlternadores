<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S-85: descripcion y codigo_referencia de una referencia se guardan siempre en mayuscula
 * (mb_strtoupper(trim())). Esta migracion pasa a mayuscula las existentes. codigo_fabricante no se toca.
 *
 * Antes de escribir revisa que, ya en mayuscula, no queden dos referencias del mismo proveedor con el
 * mismo codigo_referencia; si las hay ABORTA sin cambiar nada. (Con la collation actual,
 * utf8mb4_unicode_ci, la unicidad ya ignora mayusculas y no deberia haber ninguno; la revision cubre
 * una collation _bin o _cs.)
 *
 * DB::table salta el trait Auditable (RF-11), asi que deja una fila resumen en auditoria
 * (sin usuario: la hizo la migracion; campo 'S-85 mayusculas').
 *
 * Sin reverso: la capitalizacion original no se conserva y no se puede reconstruir.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mayus = fn (string $t): string => mb_strtoupper(trim($t));

        $vistos = [];
        $choques = [];
        DB::table('referencias')->orderBy('id')->select(['id', 'proveedor_id', 'codigo_referencia'])
            ->each(function ($r) use (&$vistos, &$choques, $mayus) {
                $llave = $r->proveedor_id.'|'.$mayus($r->codigo_referencia);
                if (isset($vistos[$llave])) {
                    $choques[] = "proveedor {$r->proveedor_id}: ids {$vistos[$llave]} y {$r->id} ('".$mayus($r->codigo_referencia)."')";
                } else {
                    $vistos[$llave] = $r->id;
                }
            });

        if ($choques !== []) {
            throw new RuntimeException('S-85: no se puede pasar codigo_referencia a mayuscula; quedarian duplicados por proveedor: '
                .implode('; ', array_slice($choques, 0, 20)).'. Resuelvalos a mano y vuelva a migrar.');
        }

        DB::transaction(function () use ($mayus) {
            $cambiadas = 0;
            DB::table('referencias')->orderBy('id')->select(['id', 'descripcion', 'codigo_referencia'])
                ->each(function ($r) use ($mayus, &$cambiadas) {
                    $nuevo = ['descripcion' => $mayus($r->descripcion), 'codigo_referencia' => $mayus($r->codigo_referencia)];
                    if ($nuevo['descripcion'] !== $r->descripcion || $nuevo['codigo_referencia'] !== $r->codigo_referencia) {
                        DB::table('referencias')->where('id', $r->id)->update($nuevo);
                        $cambiadas++;
                    }
                });

            if ($cambiadas > 0) {
                DB::table('auditoria')->insert([
                    'tabla' => 'referencias',
                    'registro_id' => 0, // resumen de la migracion, no de una fila concreta
                    'campo' => 'S-85 mayusculas (descripcion, codigo_referencia)',
                    'valor_anterior' => null,
                    'valor_nuevo' => "{$cambiadas} referencias pasadas a mayuscula",
                    'usuario_id' => null,
                    'created_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Sin reverso: el texto original (mayusculas y minusculas) no se guardo.
    }
};
