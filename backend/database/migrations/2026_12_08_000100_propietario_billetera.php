<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S-88 (RF-24): dueño de una billetera. Las cuentas de pago no cambian de identidad: Nequi, Daviplata
 * y las Llaves siguen siendo filas de cuentas_pago. El dueño es opcional y solo se usa en billeteras;
 * sin él, la cuenta sigue operando y el reporte la agrupa en "Sin dueño". No se borra (tiene cuentas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('propietarios', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 60)->unique();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::table('cuentas_pago', function (Blueprint $table) {
            $table->foreignId('propietario_id')->nullable()->after('tipo')->constrained('propietarios')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cuentas_pago', function (Blueprint $table) {
            $table->dropConstrainedForeignId('propietario_id');
        });

        Schema::dropIfExists('propietarios');
    }
};
