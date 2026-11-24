<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RF-15: abonos de clientes. Inmutables: no se editan ni se borran. Anular un abono es un
 * movimiento nuevo en abono_anulaciones, con usuario, fecha y motivo; UNIQUE(abono_id) impide
 * anularlo dos veces.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Merge cesar + legri: las tablas las crea 2026_11_23_001000_abonos_clientes (mismas columnas;
        // luego 2026_11_23_001300 agrega venta_id, S-32). Esta solo las crea en una base donde aquella
        // no corrió, para no fallar con "la tabla ya existe".
        if (Schema::hasTable('abonos_clientes')) {
            return;
        }

        Schema::create('abonos_clientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->date('fecha');
            $table->decimal('valor', 14, 2);
            $table->string('medio', 30);
            $table->string('referencia', 60)->nullable();
            $table->string('nota', 255)->nullable();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['cliente_id', 'fecha']);
        });

        Schema::create('abono_anulaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('abono_id')->unique()->constrained('abonos_clientes')->restrictOnDelete();
            $table->string('motivo', 500);
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        // Las tablas son de 2026_11_23_001000_abonos_clientes: su down() las borra.
    }
};
