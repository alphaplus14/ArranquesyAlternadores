<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S-84 (RF-25, ajusta S-49 y S-53): la apertura del día es automática (el efectivo contado del último
 * cierre vigente anterior). Una fila en `aperturas_caja` es ahora un CAMBIO MANUAL de esa apertura y
 * lleva su motivo (obligatorio cuando difiere de la del cierre anterior; nullable para las filas que
 * ya existían y para el primer día sin cierres). No destructiva: solo agrega la columna.
 * El último cierre vigente anterior a una fecha usa el índice existente de cierres_caja.fecha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aperturas_caja', function (Blueprint $table) {
            $table->string('motivo', 200)->nullable()->after('valor');
        });
    }

    public function down(): void
    {
        Schema::table('aperturas_caja', function (Blueprint $table) {
            $table->dropColumn('motivo');
        });
    }
};
