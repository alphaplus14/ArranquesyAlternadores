<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RF-04 / RF-05 (S-91): la lista de Compras ordena por fecha y id descendentes y filtra por mes o
 * rango de fechas; el selector "Mes" agrupa por fecha. No destructiva: solo agrega el índice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('ingresos', 'ingresos_fecha_id_index')) {
            Schema::table('ingresos', function (Blueprint $table) {
                $table->index(['fecha', 'id'], 'ingresos_fecha_id_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('ingresos', 'ingresos_fecha_id_index')) {
            Schema::table('ingresos', function (Blueprint $table) {
                $table->dropIndex('ingresos_fecha_id_index');
            });
        }
    }
};
