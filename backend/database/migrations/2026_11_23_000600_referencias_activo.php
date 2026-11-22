<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S-26 (RF-02, RF-11): una referencia se desactiva en lugar de borrarse (tiene historial). El
 * listado filtra por activo y ordena por código: índice compuesto (activo, codigo_referencia).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referencias', function (Blueprint $table) {
            $table->boolean('activo')->default(true)->after('existencia');
            $table->index(['activo', 'codigo_referencia']);
        });
    }

    public function down(): void
    {
        Schema::table('referencias', function (Blueprint $table) {
            $table->dropIndex(['activo', 'codigo_referencia']);
            $table->dropColumn('activo');
        });
    }
};
