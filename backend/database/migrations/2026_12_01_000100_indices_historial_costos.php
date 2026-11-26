<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S-71: último costo e historial de costos de compra (solo lectura).
 * - ingreso_items(referencia_id, ingreso_id): las líneas de una referencia llegan por el índice con
 *   el ingreso a la mano para el join (ya existía el índice simple de referencia_id).
 * - ingresos(estado, fecha): confirmados ordenados por la fecha del documento.
 * Solo agrega índices: no cambia datos ni columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingreso_items', function (Blueprint $table) {
            $table->index(['referencia_id', 'ingreso_id'], 'ingreso_items_referencia_ingreso_index');
        });
        Schema::table('ingresos', function (Blueprint $table) {
            $table->index(['estado', 'fecha'], 'ingresos_estado_fecha_index');
        });
    }

    public function down(): void
    {
        Schema::table('ingreso_items', function (Blueprint $table) {
            $table->dropIndex('ingreso_items_referencia_ingreso_index');
        });
        Schema::table('ingresos', function (Blueprint $table) {
            $table->dropIndex('ingresos_estado_fecha_index');
        });
    }
};
