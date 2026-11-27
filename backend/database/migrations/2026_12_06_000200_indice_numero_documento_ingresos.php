<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RF-05 / RF-20 (S-85, busqueda de compras): la lista de compras busca por numero_documento LIKE 'q%'
 * sin fijar proveedor; el unico indice existente lo lleva despues de proveedor_id y tipo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingresos', function (Blueprint $table) {
            $table->index('numero_documento');
        });
    }

    public function down(): void
    {
        Schema::table('ingresos', function (Blueprint $table) {
            $table->dropIndex(['numero_documento']);
        });
    }
};
