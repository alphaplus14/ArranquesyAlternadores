<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RF-09 / RF-10 / S-22: descuentos de línea en porcentaje, IVA por línea (19, 5, 0…) y descuento
 * del documento en pesos o en porcentaje.
 *
 * ingreso_items:
 * - descuento_porcentaje (0 a 100) reemplaza al descuento en pesos `descuento`; los valores que
 *   hubiera se convierten: pesos / (cantidad × costo) × 100, con 2 decimales.
 * - iva_porcentaje pasa a defecto 0 y absorbe a `sin_iva`: una línea sin IVA es iva_porcentaje = 0.
 *
 * ingresos:
 * - descuento_total se renombra descuento_documento (siempre en pesos).
 * - descuento_documento_porcentaje: si el descuento se digitó en %, se guarda aquí mientras el
 *   ingreso es borrador y los pesos se recalculan con las líneas; al confirmar queda solo en pesos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingreso_items', function (Blueprint $table) {
            $table->decimal('descuento_porcentaje', 5, 2)->default(0)->after('costo_unitario');
        });

        DB::table('ingreso_items')
            ->where('descuento', '>', 0)
            ->whereRaw('cantidad * costo_unitario > 0')
            ->update(['descuento_porcentaje' => DB::raw('LEAST(100, ROUND(descuento / (cantidad * costo_unitario) * 100, 2))')]);
        DB::table('ingreso_items')->where('sin_iva', true)->update(['iva_porcentaje' => 0]);

        Schema::table('ingreso_items', function (Blueprint $table) {
            $table->dropColumn(['descuento', 'sin_iva']);
            $table->decimal('iva_porcentaje', 5, 2)->default(0)->change();
        });

        Schema::table('ingresos', function (Blueprint $table) {
            $table->renameColumn('descuento_total', 'descuento_documento');
        });
        Schema::table('ingresos', function (Blueprint $table) {
            $table->decimal('descuento_documento_porcentaje', 5, 2)->nullable()->after('descuento_documento');
        });
    }

    public function down(): void
    {
        Schema::table('ingresos', function (Blueprint $table) {
            $table->dropColumn('descuento_documento_porcentaje');
        });
        Schema::table('ingresos', function (Blueprint $table) {
            $table->renameColumn('descuento_documento', 'descuento_total');
        });

        Schema::table('ingreso_items', function (Blueprint $table) {
            $table->decimal('descuento', 12, 2)->default(0)->after('costo_unitario');
            $table->boolean('sin_iva')->default(false)->after('iva_porcentaje');
            $table->decimal('iva_porcentaje', 5, 2)->default(19)->change();
        });

        DB::table('ingreso_items')->update([
            'descuento' => DB::raw('ROUND(cantidad * costo_unitario * descuento_porcentaje / 100, 2)'),
            'sin_iva' => DB::raw('iva_porcentaje = 0'),
        ]);

        Schema::table('ingreso_items', function (Blueprint $table) {
            $table->dropColumn('descuento_porcentaje');
        });
    }
};
