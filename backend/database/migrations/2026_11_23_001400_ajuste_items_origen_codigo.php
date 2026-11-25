<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RF-21 / RF-12 (S-34, ajusta S-27 y S-31): una salida también acepta el código del proveedor o del
 * fabricante y el sistema elige la unidad. ajuste_items.origen_codigo = 'etiqueta' | 'referencia',
 * independiente de metodo_captura. Inmutable: solo se escribe al crear. Los ítems existentes quedan
 * como 'etiqueta' (antes solo se aceptaba la etiqueta del local).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ajuste_items', function (Blueprint $table) {
            $table->enum('origen_codigo', ['etiqueta', 'referencia'])->default('etiqueta')->after('metodo_captura');
        });
    }

    public function down(): void
    {
        Schema::table('ajuste_items', function (Blueprint $table) {
            $table->dropColumn('origen_codigo');
        });
    }
};
