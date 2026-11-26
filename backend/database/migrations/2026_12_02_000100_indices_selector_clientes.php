<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RF-13 / RF-14 (S-72): índices del selector de cliente de la venta a crédito.
 * - clientes(activo, nombre): búsqueda rápida solo entre activos, ordenada por nombre.
 * - clientes(documento) ya existe (único, de la creación de la tabla): no se duplica.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('clientes', ['activo', 'nombre'])) {
            Schema::table('clientes', fn (Blueprint $table) => $table->index(['activo', 'nombre']));
        }
        if (! Schema::hasIndex('clientes', ['documento'])) {
            Schema::table('clientes', fn (Blueprint $table) => $table->index('documento'));
        }
    }

    public function down(): void
    {
        Schema::table('clientes', fn (Blueprint $table) => $table->dropIndex(['activo', 'nombre']));
    }
};
