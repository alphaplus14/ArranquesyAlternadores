<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S-23: la empresa no maneja marcas; se elimina la tabla. Las filas de `auditoria` con
 * tabla = 'marcas' se conservan (la auditoría es inmutable). Los nombres se recuperan del respaldo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('marcas');
    }

    public function down(): void
    {
        Schema::create('marcas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 80)->unique();
            $table->timestamps();
        });
    }
};
