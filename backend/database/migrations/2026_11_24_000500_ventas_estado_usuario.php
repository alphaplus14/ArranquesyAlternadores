<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RF-06 (S-40; en el pedido S-37): el mostrador retoma TODAS sus ventas abiertas y el admin las de
 * todos. (usuario_id, estado) ya sirve al mostrador; (estado, usuario_id) sirve al listado del admin,
 * que filtra solo por estado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->index(['estado', 'usuario_id']);
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropIndex(['estado', 'usuario_id']);
        });
    }
};
