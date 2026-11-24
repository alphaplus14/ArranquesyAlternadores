<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RF-21: rol contable. RF-17 (ajuste, adenda v1.3): un pago puede ligarse a un ingreso a crédito
 * concreto; sin ingreso se aplica FIFO como antes. El pago sigue siendo inmutable.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY rol ENUM('admin','logistica','mostrador','contable') NOT NULL DEFAULT 'mostrador'");

        Schema::table('pagos_proveedores', function (Blueprint $table) {
            $table->foreignId('ingreso_id')->nullable()->after('proveedor_id')->constrained('ingresos')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pagos_proveedores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ingreso_id');
        });

        DB::statement("ALTER TABLE users MODIFY rol ENUM('admin','logistica','mostrador') NOT NULL DEFAULT 'mostrador'");
    }
};
