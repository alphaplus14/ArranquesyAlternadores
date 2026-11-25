<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RF-03 / RF-06 / RF-12 (S-31, modifica la regla 3): la venta de mostrador acepta la etiqueta del
 * local o el código del proveedor/fabricante de la referencia (S-25).
 *
 * - venta_items.origen_codigo y movimientos_inventario.origen_codigo: 'etiqueta' | 'referencia'.
 *   Independiente de metodo_captura (escaneado | digitado). Se escribe solo al crear (inmutables).
 * - unidades.etiqueta_impresa_at: primera impresión de la etiqueta de la unidad. Al vender por
 *   código del proveedor se prefieren las unidades sin etiqueta impresa. Las unidades anteriores a
 *   esta migración quedan en null (no se sabe si se imprimieron).
 * - Índice (referencia_id, estado, etiqueta_impresa_at): InnoDB agrega el id al final, así que
 *   "disponibles sin etiqueta, la más antigua" se lee en orden de índice con LIMIT 1 (bloquea una
 *   sola fila con SKIP LOCKED, sin ordenar en memoria).
 * - referencias.codigo_referencia y codigo_fabricante ya tienen índice simple (migraciones previas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venta_items', function (Blueprint $table) {
            $table->enum('origen_codigo', ['etiqueta', 'referencia'])->default('etiqueta')->after('metodo_captura');
        });

        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->enum('origen_codigo', ['etiqueta', 'referencia'])->default('etiqueta')->after('metodo_captura');
        });

        Schema::table('unidades', function (Blueprint $table) {
            $table->timestamp('etiqueta_impresa_at')->nullable()->after('estado');
            $table->index(['referencia_id', 'estado', 'etiqueta_impresa_at'], 'unidades_ref_estado_etiqueta_index');
        });
    }

    public function down(): void
    {
        Schema::table('unidades', function (Blueprint $table) {
            $table->dropIndex('unidades_ref_estado_etiqueta_index');
            $table->dropColumn('etiqueta_impresa_at');
        });

        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->dropColumn('origen_codigo');
        });

        Schema::table('venta_items', function (Blueprint $table) {
            $table->dropColumn('origen_codigo');
        });
    }
};
