<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S-23 (RF-01 / RF-02): la calidad (fino o genérico) distingue, junto con el código y el
 * proveedor, las referencias de un mismo repuesto (ej. ZM860 fino y ZZM860 genérico). Opcional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referencias', function (Blueprint $table) {
            $table->enum('calidad', ['fino', 'generico'])->nullable()->after('codigo_referencia');
        });
    }

    public function down(): void
    {
        Schema::table('referencias', function (Blueprint $table) {
            $table->dropColumn('calidad');
        });
    }
};
