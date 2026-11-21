<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RF-14: venta a crédito.
 *
 * - referencias.iva_venta_porcentaje: 19 por defecto, 0 = sin IVA (S-15).
 * - ventas.forma_pago, cliente_id y factura_contapyme se fijan al crear; vence_el al cerrar
 *   (fecha de cierre + clientes.plazo_dias, S-11). factura_contapyme es solo texto de
 *   referencia: no hay integración con Contapyme.
 * - venta_items.precio_unitario e iva_porcentaje se copian de la referencia al escanear (S-10).
 *   Son nullable porque las ventas anteriores no tienen precio y no se rellenan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referencias', function (Blueprint $table) {
            $table->decimal('iva_venta_porcentaje', 5, 2)->default(19)->after('precio_venta');
        });

        Schema::table('ventas', function (Blueprint $table) {
            $table->enum('forma_pago', ['contado', 'credito'])->default('contado')->after('estado');
            $table->foreignId('cliente_id')->nullable()->after('forma_pago')->constrained('clientes')->restrictOnDelete();
            $table->date('vence_el')->nullable()->after('cerrada_at');
            $table->string('factura_contapyme', 40)->nullable()->after('vence_el');

            $table->index(['forma_pago', 'cliente_id', 'vence_el']);
        });

        Schema::table('venta_items', function (Blueprint $table) {
            $table->decimal('precio_unitario', 12, 2)->nullable()->after('unidad_id');
            $table->decimal('iva_porcentaje', 5, 2)->nullable()->after('precio_unitario');
        });
    }

    public function down(): void
    {
        Schema::table('venta_items', function (Blueprint $table) {
            $table->dropColumn(['precio_unitario', 'iva_porcentaje']);
        });

        Schema::table('ventas', function (Blueprint $table) {
            $table->dropIndex(['forma_pago', 'cliente_id', 'vence_el']);
            $table->dropConstrainedForeignId('cliente_id');
            $table->dropColumn(['forma_pago', 'vence_el', 'factura_contapyme']);
        });

        Schema::table('referencias', function (Blueprint $table) {
            $table->dropColumn('iva_venta_porcentaje');
        });
    }
};
