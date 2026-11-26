<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S-70: preferencias de pantalla por usuario (tamaño de letra, densidad, color de acento, filas por
 * página y menú plegado). Solo admin, logística y contable. Guarda únicamente lo que el usuario
 * cambió; lo demás lo resuelve el frontend por defecto según el rol. No son datos del negocio: la
 * columna no se audita (User la excluye de Auditable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('preferencias')->nullable()->after('activo');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('preferencias');
        });
    }
};
