<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RF-04 (S-06): al confirmar una factura por QR se registra la diferencia contra el total del QR.
 *
 * - confirmado_por: quien confirmó (usuario_id es quien creó el borrador). Si hubo diferencia
 *   fuera de la tolerancia, es también quien la aceptó.
 * - diferencia_qr: total digitado − total del QR al confirmar (negativo = llegó menos). Queda
 *   guardada aunque esté dentro de la tolerancia; null si no es factura_qr o el QR no trajo total.
 * - diferencia_aceptada / diferencia_motivo: la diferencia superó la tolerancia y se aceptó con motivo.
 *
 * Los ingresos confirmados antes de esta migración quedan con estas columnas en null/false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingresos', function (Blueprint $table) {
            $table->foreignId('confirmado_por')->nullable()->after('confirmado_at')
                ->constrained('users')->restrictOnDelete();
            $table->decimal('diferencia_qr', 14, 2)->nullable()->after('total_qr');
            $table->boolean('diferencia_aceptada')->default(false)->after('diferencia_qr');
            $table->string('diferencia_motivo', 500)->nullable()->after('diferencia_aceptada');

            // Filtro "con diferencia" del listado de ingresos (regla 13).
            $table->index(['diferencia_aceptada', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('ingresos', function (Blueprint $table) {
            $table->dropIndex(['diferencia_aceptada', 'id']);
            $table->dropConstrainedForeignId('confirmado_por');
            $table->dropColumn(['diferencia_qr', 'diferencia_aceptada', 'diferencia_motivo']);
        });
    }
};
