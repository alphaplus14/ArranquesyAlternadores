<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RF-21 (S-87): historial de movimientos por producto en Logística. Los filtros por tipo, usuario y
 * método de captura se ordenan por fecha; sin estos índices recorrerían toda la tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->index(['tipo', 'created_at'], 'mov_inv_tipo_fecha_idx');
            $table->index(['usuario_id', 'created_at'], 'mov_inv_usuario_fecha_idx');
            $table->index(['metodo_captura', 'created_at'], 'mov_inv_metodo_fecha_idx');
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->dropIndex('mov_inv_tipo_fecha_idx');
            $table->dropIndex('mov_inv_usuario_fecha_idx');
            $table->dropIndex('mov_inv_metodo_fecha_idx');
        });
    }
};
