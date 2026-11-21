<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RF-16: condición de pago de las compras.
 *
 * - ingresos.forma_pago: contado (por defecto) o crédito. Solo los ingresos a crédito generarán
 *   deuda (RF-17); una remisión no la genera sola mientras no esté ligada a una factura (RF-19).
 * - ingresos.vence_el: el vencimiento que imprime el documento, digitado (S-21). Null en contado.
 * - ingresos.total: se fija al confirmar con el cálculo de RF-09 (ajuste). Los ingresos ya
 *   confirmados antes de esta migración quedan en null.
 * - Índice (proveedor_id, forma_pago, vence_el) para la cuenta por pagar y su antigüedad.
 * - proveedores.plazo_dias: solo prellena el vencimiento en pantalla; nunca se calcula con él.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingresos', function (Blueprint $table) {
            $table->enum('forma_pago', ['contado', 'credito'])->default('contado')->after('tipo');
            $table->date('vence_el')->nullable()->after('fecha');
            $table->decimal('total', 14, 2)->nullable()->after('descuento_total');
            $table->index(['proveedor_id', 'forma_pago', 'vence_el']);
        });

        Schema::table('proveedores', function (Blueprint $table) {
            $table->unsignedSmallInteger('plazo_dias')->nullable()->after('nit');
        });
    }

    public function down(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->dropColumn('plazo_dias');
        });

        Schema::table('ingresos', function (Blueprint $table) {
            $table->dropIndex(['proveedor_id', 'forma_pago', 'vence_el']);
            $table->dropColumn(['forma_pago', 'vence_el', 'total']);
        });
    }
};
