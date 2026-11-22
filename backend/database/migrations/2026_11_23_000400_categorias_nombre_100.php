<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S-24: el nombre de una categoría admite hasta 100 caracteres (antes 80). Se conserva el índice
 * único. Al revertir vuelve a 80: falla si alguna categoría quedó con más de 80 caracteres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->string('nombre', 100)->change();
        });
    }

    public function down(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->string('nombre', 80)->change();
        });
    }
};
