<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RF-32 / S-77: la consulta de precios de compra filtra ingresos confirmados por fecha del documento.
 * `estado` ya tenía índice propio; este compuesto cubre el filtro y el orden por fecha. S-71 crea el
 * mismo índice (2026_12_01_000100): en una base donde aquella ya corrió, aquí no se repite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('ingresos', 'ingresos_estado_fecha_index')) {
            Schema::table('ingresos', function (Blueprint $table) {
                $table->index(['estado', 'fecha'], 'ingresos_estado_fecha_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('ingresos', 'ingresos_estado_fecha_index')) {
            Schema::table('ingresos', function (Blueprint $table) {
                $table->dropIndex('ingresos_estado_fecha_index');
            });
        }
    }
};
