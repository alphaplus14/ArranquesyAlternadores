<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RF-15 / RF-18 (S-32, reemplaza S-12): un abono puede ir dirigido a una venta a crédito ("factura").
 * venta_id NULL = abono general (FIFO a las ventas más antiguas, como antes). La tabla es inmutable:
 * la columna solo se escribe al crear. Los abonos existentes quedan como generales; no se reescribe
 * nada. Índice (cliente_id, venta_id) para sumar lo dirigido por venta de un cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('abonos_clientes', function (Blueprint $table) {
            $table->foreignId('venta_id')->nullable()->after('cliente_id')->constrained('ventas')->restrictOnDelete();
            $table->index(['cliente_id', 'venta_id']);
        });
    }

    public function down(): void
    {
        Schema::table('abonos_clientes', function (Blueprint $table) {
            $table->dropForeign(['venta_id']);
            $table->dropIndex(['cliente_id', 'venta_id']);
            $table->dropColumn('venta_id');
        });
    }
};
