<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RF-06 / RF-07 (S-46): estado `descartada` para limpiar ventas abiertas sin ítems vigentes. La venta
 * no se borra: conserva sus filas y queda con fecha (descartada_at) y quién (descartada_por; NULL =
 * descarte automático del sistema). Sin índice nuevo: (estado, usuario_id) ya sirve.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! str_contains(DB::selectOne("SHOW COLUMNS FROM ventas LIKE 'estado'")->Type, 'descartada')) {
            DB::statement("ALTER TABLE ventas MODIFY estado ENUM('abierta','cerrada','descartada') NOT NULL DEFAULT 'abierta'");
        }

        Schema::table('ventas', function (Blueprint $table) {
            if (! Schema::hasColumn('ventas', 'descartada_at')) {
                $table->timestamp('descartada_at')->nullable()->after('cerrada_at');
            }
            if (! Schema::hasColumn('ventas', 'descartada_por')) {
                $table->foreignId('descartada_por')->nullable()->after('descartada_at')->constrained('users')->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        $descartadas = DB::table('ventas')->where('estado', 'descartada')->count();
        if ($descartadas > 0) {
            throw new RuntimeException("No se puede revertir S-46: hay {$descartadas} venta(s) descartada(s). Las ventas nunca se borran (RF-07) y sin este estado quedarían sin un valor válido.");
        }

        Schema::table('ventas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('descartada_por');
            $table->dropColumn('descartada_at');
        });

        DB::statement("ALTER TABLE ventas MODIFY estado ENUM('abierta','cerrada') NOT NULL DEFAULT 'abierta'");
    }
};
