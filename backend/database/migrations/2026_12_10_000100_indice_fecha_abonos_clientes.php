<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S-97 (RF-17, RF-18, RF-29): "Cartera recuperada" filtra los abonos por fecha sin fijar cliente y
 * ordena por fecha e id descendentes; el único índice con fecha la lleva después de cliente_id.
 * No destructiva: solo agrega el índice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('abonos_clientes', 'abonos_clientes_fecha_id_index')) {
            Schema::table('abonos_clientes', function (Blueprint $table) {
                $table->index(['fecha', 'id'], 'abonos_clientes_fecha_id_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('abonos_clientes', 'abonos_clientes_fecha_id_index')) {
            Schema::table('abonos_clientes', function (Blueprint $table) {
                $table->dropIndex('abonos_clientes_fecha_id_index');
            });
        }
    }
};
