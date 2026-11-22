<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S-25 (RF-20, RF-02): el código de barras o QR que trae el producto o su caja, opcional. Único
 * por proveedor (MySQL admite varios NULL) y con índice propio para buscarlo exacto en todos los
 * proveedores (GET /buscar). Sin backfill: las referencias existentes quedan como están.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referencias', function (Blueprint $table) {
            $table->string('codigo_fabricante', 120)->nullable()->after('codigo_referencia');
            $table->index('codigo_fabricante');
            $table->unique(['proveedor_id', 'codigo_fabricante']);
        });
    }

    public function down(): void
    {
        Schema::table('referencias', function (Blueprint $table) {
            $table->dropUnique(['proveedor_id', 'codigo_fabricante']);
            $table->dropIndex(['codigo_fabricante']);
            $table->dropColumn('codigo_fabricante');
        });
    }
};
