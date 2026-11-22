<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RF-17: pagos a proveedores, con el mismo esquema que los abonos de clientes (RF-15).
 * Inmutables; anular un pago es un movimiento nuevo en pago_anulaciones, una sola vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos_proveedores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->constrained('proveedores')->restrictOnDelete();
            $table->date('fecha');
            $table->decimal('valor', 14, 2);
            $table->string('medio', 30);
            $table->string('referencia', 60)->nullable();
            $table->string('nota', 255)->nullable();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['proveedor_id', 'fecha']);
        });

        Schema::create('pago_anulaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pago_id')->unique()->constrained('pagos_proveedores')->restrictOnDelete();
            $table->string('motivo', 500);
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pago_anulaciones');
        Schema::dropIfExists('pagos_proveedores');
    }
};
