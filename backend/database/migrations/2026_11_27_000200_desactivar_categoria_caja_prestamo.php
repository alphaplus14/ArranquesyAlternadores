<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RF-27 (S-55): los préstamos a empleados ahora se registran en la cuenta de empleados, no como un
 * gasto suelto de caja. Si existe la categoría de caja 'PRESTAMO' (grupo vario, Fase B) y está
 * activa, se desactiva y queda la fila de auditoría (regla 9; sin usuario: la hizo la migración).
 * Los movimientos antiguos con esa categoría no se tocan.
 */
return new class extends Migration
{
    public function up(): void
    {
        $categoria = DB::table('categorias_caja')
            ->where('nombre', 'PRESTAMO')->where('grupo', 'vario')->where('activa', true)
            ->first(['id']);

        if (! $categoria) {
            return;
        }

        DB::transaction(function () use ($categoria) {
            DB::table('categorias_caja')->where('id', $categoria->id)->update(['activa' => false, 'updated_at' => now()]);
            DB::table('auditoria')->insert([
                'tabla' => 'categorias_caja',
                'registro_id' => $categoria->id,
                'campo' => 'activa',
                'valor_anterior' => '1',
                'valor_nuevo' => '0',
                'usuario_id' => null,
                'created_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // Sin reverso automático: reactivarla es decisión del admin (queda auditada).
    }
};
