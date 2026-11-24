<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RF-21 (S-27): logística y admin sacan unidades del inventario (baja) y corrigen una baja hecha
 * por error (reintegro). RF-03: una unidad puede quedar `retirada`. RF-12: cada unidad del ajuste
 * guarda si se escaneó o se digitó.
 *
 * - unidades.estado y movimientos_inventario.tipo son enum de BD: se amplían.
 * - ajustes_inventario y ajuste_items son inmutables (sin updated_at, sin rutas DELETE).
 *
 * down(): se detiene si ya hay unidades retiradas o movimientos de baja/reintegro; no los borra
 * ni los convierte a otro estado (perdería historial).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unidades', function (Blueprint $table) {
            $table->enum('estado', ['disponible', 'vendida', 'retirada'])->default('disponible')->change();
        });

        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->enum('tipo', ['ingreso', 'venta', 'reverso', 'baja', 'reintegro'])->change();
        });

        Schema::create('ajustes_inventario', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo', ['baja', 'reintegro']);
            $table->enum('motivo', ['garantia', 'devolucion_proveedor', 'dano', 'perdida', 'otro', 'error_de_registro']);
            $table->string('nota', 500)->nullable(); // obligatoria si el motivo es `otro`
            $table->foreignId('usuario_id')->index()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            // Historial: filtros por tipo y motivo con orden por fecha, y por fecha sola.
            $table->index(['tipo', 'motivo', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('ajuste_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ajuste_id')->index()->constrained('ajustes_inventario')->restrictOnDelete();
            $table->foreignId('unidad_id')->index()->constrained('unidades')->restrictOnDelete();
            $table->enum('metodo_captura', ['escaneado', 'digitado']); // RF-12
        });
    }

    public function down(): void
    {
        $retiradas = DB::table('unidades')->where('estado', 'retirada')->count();
        $movimientos = DB::table('movimientos_inventario')->whereIn('tipo', ['baja', 'reintegro'])->count();

        if ($retiradas > 0 || $movimientos > 0) {
            throw new RuntimeException(
                "Hay {$retiradas} unidades retiradas y {$movimientos} movimientos de baja o reintegro; no se revierte para no perder historial."
            );
        }

        Schema::dropIfExists('ajuste_items');
        Schema::dropIfExists('ajustes_inventario');

        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->enum('tipo', ['ingreso', 'venta', 'reverso'])->change();
        });

        Schema::table('unidades', function (Blueprint $table) {
            $table->enum('estado', ['disponible', 'vendida'])->default('disponible')->change();
        });
    }
};
