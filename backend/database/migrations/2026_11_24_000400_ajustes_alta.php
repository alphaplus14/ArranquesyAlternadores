<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RF-21 / RF-03 (S-39; en el pedido se llamó S-36): entrada por ajuste ("alta") de unidades que
 * aparecen en un inventario físico y no vienen de ninguna factura ni remisión.
 *
 * - ajustes_inventario.tipo + 'alta'; motivo + 'sobrante_inventario', 'sin_factura' (alta) y
 *   'faltante_inventario' (baja).
 * - movimientos_inventario.tipo + 'alta'.
 * - unidades.ingreso_item_id pasa a ser opcional (una unidad de alta no viene de un ingreso) y
 *   unidades.ajuste_id (opcional) dice de qué ajuste de alta salió. Toda unidad tiene uno de los dos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ajustes_inventario', function (Blueprint $table) {
            $table->enum('tipo', ['baja', 'reintegro', 'alta'])->change();
            $table->enum('motivo', ['garantia', 'devolucion_proveedor', 'dano', 'perdida', 'otro', 'error_de_registro', 'sobrante_inventario', 'sin_factura', 'faltante_inventario'])->change();
        });

        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->enum('tipo', ['ingreso', 'venta', 'reverso', 'baja', 'reintegro', 'alta'])->change();
        });

        Schema::table('unidades', function (Blueprint $table) {
            $table->unsignedBigInteger('ingreso_item_id')->nullable()->change();
            $table->foreignId('ajuste_id')->nullable()->after('ingreso_item_id')->constrained('ajustes_inventario')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // No se pierde historial en silencio: si ya hay altas, no se puede revertir.
        if (DB::table('ajustes_inventario')->where('tipo', 'alta')->exists()
            || DB::table('ajustes_inventario')->whereIn('motivo', ['sobrante_inventario', 'sin_factura', 'faltante_inventario'])->exists()) {
            throw new RuntimeException('Hay ajustes de alta o con los motivos nuevos: no se puede revertir sin perder historial.');
        }

        Schema::table('unidades', function (Blueprint $table) {
            $table->dropForeign(['ajuste_id']);
            $table->dropColumn('ajuste_id');
            $table->unsignedBigInteger('ingreso_item_id')->nullable(false)->change();
        });

        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->enum('tipo', ['ingreso', 'venta', 'reverso', 'baja', 'reintegro'])->change();
        });

        Schema::table('ajustes_inventario', function (Blueprint $table) {
            $table->enum('tipo', ['baja', 'reintegro'])->change();
            $table->enum('motivo', ['garantia', 'devolucion_proveedor', 'dano', 'perdida', 'otro', 'error_de_registro'])->change();
        });
    }
};
