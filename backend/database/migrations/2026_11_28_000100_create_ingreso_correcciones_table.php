<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RF-28 (S-58, S-60, S-61): corrección de un ingreso confirmado (factura o remisión). Cada
 * corrección deja una fila inmutable con el motivo, la foto del antes y el después, las unidades
 * creadas y retiradas y los totales. Sin DELETE. Los campos cambiados también quedan en la
 * auditoría general (RF-11). Índices para la lista del admin (fecha, usuario) y el filtro
 * "corregidos" de la lista de ingresos (ingreso_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingreso_correcciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingreso_id')->index()->constrained('ingresos')->restrictOnDelete();
            $table->foreignId('usuario_id')->index()->constrained('users')->restrictOnDelete();
            $table->string('motivo', 500);
            $table->json('antes');
            $table->json('despues');
            $table->unsignedInteger('unidades_creadas')->default(0);
            $table->unsignedInteger('unidades_retiradas')->default(0);
            $table->decimal('total_anterior', 14, 2)->nullable();
            $table->decimal('total_nuevo', 14, 2);
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingreso_correcciones');
    }
};
