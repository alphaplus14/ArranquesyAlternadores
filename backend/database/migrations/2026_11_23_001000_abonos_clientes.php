<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RF-15 (adenda v1.2, paso 4; S-30): abonos de clientes y su anulación. Ambas tablas son
 * inmutables (trait Inmutable, sin updated_at, sin rutas DELETE). Un abono anulado sigue visible y
 * no cuenta en el saldo. El índice (forma_pago, cliente_id, vence_el) de ventas ya existe
 * (migración 2026_11_20_000200).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Merge con la rama de Frank: su migración 2026_11_24_000100 crea las mismas tablas, con las
        // mismas columnas. En una base donde ya corrió la suya, esta no las vuelve a crear.
        if (Schema::hasTable('abonos_clientes')) {
            return;
        }

        Schema::create('abonos_clientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->index()->constrained('clientes')->restrictOnDelete();
            $table->date('fecha');
            $table->decimal('valor', 14, 2); // > 0 (AbonoService)
            $table->string('medio', 30);     // App\Enums\MedioPago
            $table->string('referencia', 60)->nullable();
            $table->string('nota', 255)->nullable();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['cliente_id', 'fecha']);
        });

        Schema::create('abono_anulaciones', function (Blueprint $table) {
            $table->id();
            // Único: un abono se anula una sola vez.
            $table->foreignId('abono_id')->unique()->constrained('abonos_clientes')->restrictOnDelete();
            $table->string('motivo', 500);
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abono_anulaciones');
        Schema::dropIfExists('abonos_clientes');
    }
};
