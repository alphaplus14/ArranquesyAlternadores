<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RF-22 (S-28): stock mínimo general y por producto.
 *
 * - referencias.stock_minimo y codigos_universales.stock_minimo: NULL = usa el general;
 *   0 = no maneja mínimo; > 0 = mínimo propio. Una referencia de un universal se evalúa con el
 *   mínimo y la suma del universal.
 * - configuraciones: clave única y valor; `stock_minimo_general` nace en NULL (sin mínimo general).
 * - Índices: (activo, existencia) para acotar el informe por existencia; stock_minimo para que
 *   MAX(stock_minimo) sea inmediato (tope del filtro).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referencias', function (Blueprint $table) {
            $table->unsignedInteger('stock_minimo')->nullable()->after('existencia');
            $table->index(['activo', 'existencia']);
            $table->index('stock_minimo');
        });

        Schema::table('codigos_universales', function (Blueprint $table) {
            $table->unsignedInteger('stock_minimo')->nullable()->after('descripcion');
            $table->index('stock_minimo');
        });

        Schema::create('configuraciones', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 60)->unique();
            $table->string('valor', 255)->nullable();
            $table->timestamps();
        });

        DB::table('configuraciones')->insert([
            'clave' => 'stock_minimo_general',
            'valor' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('configuraciones');

        Schema::table('codigos_universales', function (Blueprint $table) {
            $table->dropIndex(['stock_minimo']);
            $table->dropColumn('stock_minimo');
        });

        Schema::table('referencias', function (Blueprint $table) {
            $table->dropIndex(['activo', 'existencia']);
            $table->dropIndex(['stock_minimo']);
            $table->dropColumn('stock_minimo');
        });
    }
};
