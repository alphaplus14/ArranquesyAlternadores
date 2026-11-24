<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RF-15 / RF-18 / RF-14 (S-30): buscar una venta a crédito por su factura de Contapyme. La búsqueda
 * es por prefijo (LIKE 'X%', sin comodín inicial), así que un índice simple sobre
 * ventas.factura_contapyme la resuelve sin recorrer la tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->index('factura_contapyme');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropIndex(['factura_contapyme']);
        });
    }
};
