<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S-29: nombre del asesor comercial del proveedor, opcional (sin teléfono ni correo).
 * El NIT obligatorio al crear se valida en ProveedorRequest: los proveedores antiguos sin NIT
 * siguen válidos, por eso la columna sigue aceptando NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->string('asesor', 120)->nullable()->after('nit');
        });
    }

    public function down(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->dropColumn('asesor');
        });
    }
};
