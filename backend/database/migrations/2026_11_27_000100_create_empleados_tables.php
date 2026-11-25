<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RF-27 (S-55): cuenta de empleados. Préstamos que el local hace a sus empleados y abonos con que
 * los pagan. Cartera independiente de la de clientes: no entra en CarteraService, sus informes ni el
 * panel. No calcula nómina: el descuento de nómina solo se REGISTRA como forma de abono.
 *
 * - empleados: catálogo (Auditable). No se borra: se desactiva (y no con saldo distinto de 0). No
 *   se enlaza con `users`.
 * - movimientos_empleado: préstamos y abonos. Inmutables (regla 8). `forma` solo en abonos (CHECK);
 *   la cuenta es obligatoria en abonos por caja y en préstamos (siempre Efectivo), lo impone el
 *   servicio. `apertura` marca el saldo inicial cargado al empezar. Índices (empleado_id, fecha)
 *   para el estado de cuenta y (fecha, tipo) para la caja del día.
 * - movimiento_empleado_anulaciones: anular = registro nuevo con motivo (UNIQUE: una sola vez).
 *   Índice created_at: la anulación resta en la caja del día en que se hace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empleados', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 120)->unique();
            $table->string('documento', 30)->nullable()->unique();
            $table->boolean('activo')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('movimientos_empleado', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empleado_id')->constrained('empleados')->restrictOnDelete();
            $table->enum('tipo', ['prestamo', 'abono']);
            $table->date('fecha');
            $table->decimal('valor', 14, 2);
            $table->enum('forma', ['caja', 'nomina'])->nullable();
            $table->foreignId('cuenta_pago_id')->nullable()->constrained('cuentas_pago')->restrictOnDelete();
            $table->boolean('apertura')->default(false);
            $table->string('nota', 255)->nullable();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['empleado_id', 'fecha']);
            $table->index(['fecha', 'tipo']);
        });
        DB::statement('ALTER TABLE movimientos_empleado ADD CONSTRAINT movimientos_empleado_valor_positivo CHECK (valor > 0)');
        DB::statement("ALTER TABLE movimientos_empleado ADD CONSTRAINT movimientos_empleado_forma_solo_abono CHECK ((tipo = 'abono' AND forma IS NOT NULL) OR (tipo = 'prestamo' AND forma IS NULL))");

        Schema::create('movimiento_empleado_anulaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movimiento_id')->unique()->constrained('movimientos_empleado')->restrictOnDelete();
            $table->string('motivo', 500);
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimiento_empleado_anulaciones');
        Schema::dropIfExists('movimientos_empleado');
        Schema::dropIfExists('empleados');
    }
};
