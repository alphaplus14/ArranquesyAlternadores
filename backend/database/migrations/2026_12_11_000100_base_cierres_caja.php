<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S-98 (RF-25, RF-26): base fija de caja. Cada cierre guarda la base vigente al cerrar. No
 * destructiva: los cierres anteriores quedan con base 0 y se comportan igual que antes (su arrastre
 * sigue siendo el contado completo). No toca efectivo_contado, efectivo_esperado ni diferencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cierres_caja', 'base')) {
            Schema::table('cierres_caja', function (Blueprint $table) {
                $table->decimal('base', 14, 2)->default(0)->after('apertura');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('cierres_caja', 'base')) {
            Schema::table('cierres_caja', function (Blueprint $table) {
                $table->dropColumn('base');
            });
        }
    }
};
