<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RF-25 / RF-26 (S-76): recaudo de documentos (facturas o remisiones a crédito de Contapyme que no se
 * registran como ventas a crédito del sistema). Solo registra el dinero que entra a la caja del día:
 * no crea ventas, no mueve inventario ni toca la cartera de clientes. Inmutables, como los
 * movimientos de caja: un error se anula con motivo (recaudo_documento_anulaciones) y se registra otro.
 * - fecha: día de caja al que se asigna (el que se está viendo), no la hora de registro.
 * - Índices: (fecha) para el día y los rangos del contable, (numero_documento) para la búsqueda,
 *   (cuenta_pago_id, fecha) para los totales por cuenta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recaudos_documento', function (Blueprint $table) {
            $table->id();
            $table->date('fecha')->index();
            $table->enum('tipo_documento', ['factura', 'remision']);
            $table->string('numero_documento', 60)->index();
            $table->decimal('valor', 14, 2);
            $table->foreignId('cuenta_pago_id')->constrained('cuentas_pago')->restrictOnDelete();
            $table->text('observacion')->nullable();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['cuenta_pago_id', 'fecha']);
        });
        DB::statement('ALTER TABLE recaudos_documento ADD CONSTRAINT recaudos_documento_valor_positivo CHECK (valor > 0)');

        Schema::create('recaudo_documento_anulaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recaudo_id')->unique()->constrained('recaudos_documento')->restrictOnDelete();
            $table->string('motivo', 500);
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recaudo_documento_anulaciones');
        Schema::dropIfExists('recaudos_documento');
    }
};
