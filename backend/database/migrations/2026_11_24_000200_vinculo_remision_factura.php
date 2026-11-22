<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RF-19: vínculo remisión–factura de compra.
 *
 * - ingresos.factura_id: solo en remisiones; la factura que las cubre. Se liga y desliga mientras
 *   la factura es borrador; al confirmarla, el vínculo es definitivo (S-17).
 * - ingreso_items.cantidad_cubierta: de la cantidad facturada, cuánto ya había entrado por las
 *   remisiones ligadas. Se fija al confirmar la factura; solo se crean unidades por la diferencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingresos', function (Blueprint $table) {
            $table->foreignId('factura_id')->nullable()->after('tipo')->constrained('ingresos')->restrictOnDelete();
        });

        Schema::table('ingreso_items', function (Blueprint $table) {
            $table->unsignedInteger('cantidad_cubierta')->default(0)->after('cantidad');
        });
    }

    public function down(): void
    {
        Schema::table('ingreso_items', function (Blueprint $table) {
            $table->dropColumn('cantidad_cubierta');
        });

        Schema::table('ingresos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('factura_id');
        });
    }
};
