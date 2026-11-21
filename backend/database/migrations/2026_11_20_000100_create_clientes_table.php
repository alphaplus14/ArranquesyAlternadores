<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RF-13: clientes, para las ventas a crédito (RF-14) y la cartera.
 * plazo_dias: plazo por defecto de 30 días (S-11). Sin borrado: se desactiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150)->index();
            $table->string('documento', 30)->nullable()->unique();
            $table->string('telefono', 30)->nullable();
            $table->unsignedSmallInteger('plazo_dias')->default(30);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
