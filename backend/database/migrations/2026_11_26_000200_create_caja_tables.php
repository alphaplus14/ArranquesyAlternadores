<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RF-25 / RF-26 (S-49 a S-54): caja diaria del mostrador. Solo REGISTRA lo que entra y sale; no es
 * contabilidad general.
 *
 * - categorias_caja: catálogo del admin por grupo (Auditable, sin DELETE). Las categorías las
 *   siembra CajaSeeder.
 * - movimientos_caja: gastos, gastos fijos, cuotas bancarias, ingresos externos y otros ingresos.
 *   Inmutables; `grupo` es copia del grupo de la categoría al registrarlo. Índice (fecha, grupo).
 * - movimiento_caja_anulaciones: anular = movimiento nuevo con motivo (UNIQUE: una sola vez).
 * - cierres_caja: foto del día al cerrarlo (inmutable). El vigente de una fecha es el que no tiene
 *   reapertura; CajaService lo impone con transacción y bloqueo (no hay UNIQUE(fecha) porque tras
 *   una reapertura se vuelve a cerrar la misma fecha).
 * - cierre_reaperturas: reabrir un cierre (solo admin, motivo obligatorio). UNIQUE(cierre_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorias_caja', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100);
            $table->enum('grupo', ['vario', 'fijo', 'financiero', 'ingreso_externo', 'otro_ingreso']);
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->unique(['nombre', 'grupo']);
            $table->index(['grupo', 'activa']);
        });

        Schema::create('movimientos_caja', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->foreignId('categoria_caja_id')->constrained('categorias_caja')->restrictOnDelete();
            $table->enum('grupo', ['vario', 'fijo', 'financiero', 'ingreso_externo', 'otro_ingreso']);
            $table->string('concepto', 150);
            $table->decimal('valor', 14, 2);
            $table->foreignId('cuenta_pago_id')->constrained('cuentas_pago')->restrictOnDelete();
            $table->string('referencia', 60)->nullable();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['fecha', 'grupo']);
        });
        DB::statement('ALTER TABLE movimientos_caja ADD CONSTRAINT movimientos_caja_valor_positivo CHECK (valor > 0)');

        Schema::create('movimiento_caja_anulaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movimiento_id')->unique()->constrained('movimientos_caja')->restrictOnDelete();
            $table->string('motivo', 500);
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('cierres_caja', function (Blueprint $table) {
            $table->id();
            $table->date('fecha')->index();
            $table->decimal('efectivo_contado', 14, 2);
            $table->decimal('efectivo_esperado', 14, 2);
            $table->decimal('diferencia', 14, 2);
            $table->decimal('saldo_anterior', 14, 2);
            $table->decimal('saldo_acumulado', 14, 2);
            $table->string('observacion', 255)->nullable();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('cerrado_at')->useCurrent();
        });

        Schema::create('cierre_reaperturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cierre_id')->unique()->constrained('cierres_caja')->restrictOnDelete();
            $table->string('motivo', 500);
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        // Fila de bloqueo de la caja: el cierre la toma FOR UPDATE; cerrar ventas, abonos, pagos,
        // devoluciones y movimientos la toman compartida (CajaService::exigirDiaAbierto).
        DB::table('secuencias')->insertOrIgnore(['nombre' => 'caja', 'valor' => 0]);
    }

    public function down(): void
    {
        Schema::dropIfExists('cierre_reaperturas');
        Schema::dropIfExists('cierres_caja');
        Schema::dropIfExists('movimiento_caja_anulaciones');
        Schema::dropIfExists('movimientos_caja');
        Schema::dropIfExists('categorias_caja');
    }
};
