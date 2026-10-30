<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de inventario.
 *
 * RF-01: código universal solo en categorías con admite_codigo_universal.
 * RF-02: la existencia del universal NO se guarda; es SUM(referencias.existencia).
 * RF-08: índice (proveedor_id, existencia) para agotados por proveedor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorias', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 80)->unique();
            // RF-01 / supuesto S-02: el admin decide referencia por referencia dentro de estas categorías.
            $table->boolean('admite_codigo_universal')->default(false);
            $table->timestamps();
        });

        // Supuesto S-03: catálogo editable, ningún nombre de marca en el código.
        Schema::create('marcas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 80)->unique();
            $table->timestamps();
        });

        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150)->index();
            $table->string('nit', 30)->nullable()->unique();
            $table->timestamps();
        });

        // RF-01: en reguladores el código es el de Transpo (ej. IM204).
        Schema::create('codigos_universales', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 60)->unique();
            $table->foreignId('categoria_id')->constrained('categorias')->restrictOnDelete();
            $table->string('descripcion')->nullable();
            $table->timestamps();
        });

        Schema::create('referencias', function (Blueprint $table) {
            $table->id();
            // RF-01: fuera del esquema universal, la referencia original es el código único.
            $table->string('codigo_referencia', 80)->index();
            $table->foreignId('marca_id')->index()->constrained('marcas')->restrictOnDelete();
            $table->foreignId('proveedor_id')->constrained('proveedores')->restrictOnDelete();
            $table->foreignId('categoria_id')->index()->constrained('categorias')->restrictOnDelete();
            $table->foreignId('codigo_universal_id')->nullable()->constrained('codigos_universales')->restrictOnDelete();
            $table->string('descripcion');
            $table->decimal('precio_venta', 12, 2)->default(0);
            // RF-02: contador. Solo lo cambia App\Services\InventarioService (o IngresoService al confirmar),
            // dentro de la misma transacción que cambia unidades. Ver supuesto S-01.
            $table->unsignedInteger('existencia')->default(0);
            $table->timestamps();

            $table->unique(['proveedor_id', 'codigo_referencia']);
            $table->index(['proveedor_id', 'existencia']); // RF-08
            $table->index(['codigo_universal_id', 'existencia']); // RF-02: SUM por universal
            $table->fullText('descripcion');
        });

        // RF-11: una fila por campo editado. Inmutable.
        Schema::create('auditoria', function (Blueprint $table) {
            $table->id();
            $table->string('tabla', 64);
            $table->unsignedBigInteger('registro_id');
            $table->string('campo', 64);
            $table->text('valor_anterior')->nullable();
            $table->text('valor_nuevo')->nullable();
            $table->foreignId('usuario_id')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tabla', 'registro_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditoria');
        Schema::dropIfExists('referencias');
        Schema::dropIfExists('codigos_universales');
        Schema::dropIfExists('proveedores');
        Schema::dropIfExists('marcas');
        Schema::dropIfExists('categorias');
    }
};
