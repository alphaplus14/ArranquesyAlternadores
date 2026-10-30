<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ingresos, unidades, ventas, reversos y movimientos.
 *
 * RF-03: unidades con código de barras propio del local.
 * RF-04 (no implementado, S-06): ingresos.tipo y ingresos.cufe preparados.
 * RF-05, RF-09, RF-10: ingreso por remisión, descuento y líneas sin IVA.
 * RF-06, RF-12: venta con descargue inmediato y metodo_captura.
 * RF-07: reversos como movimiento nuevo; ventas nunca se borran.
 */
return new class extends Migration
{
    public function up(): void
    {
        // RF-03: consecutivo del código de barras, reservado en bloque con lockForUpdate().
        Schema::create('secuencias', function (Blueprint $table) {
            $table->string('nombre', 40)->primary();
            $table->unsignedBigInteger('valor')->default(0);
        });

        Schema::create('ingresos', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo', ['remision', 'factura_xml'])->default('remision');
            $table->foreignId('proveedor_id')->constrained('proveedores')->restrictOnDelete();
            $table->string('numero_documento', 60);
            $table->string('cufe', 120)->nullable()->unique(); // RF-04, preparado
            $table->date('fecha');
            $table->decimal('descuento_total', 14, 2)->default(0); // RF-09
            $table->enum('estado', ['borrador', 'confirmado'])->default('borrador')->index();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmado_at')->nullable();
            $table->timestamps();

            $table->index(['proveedor_id', 'fecha']);
        });

        Schema::create('ingreso_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingreso_id')->constrained('ingresos')->restrictOnDelete();
            $table->foreignId('referencia_id')->index()->constrained('referencias')->restrictOnDelete();
            $table->unsignedInteger('cantidad');
            $table->decimal('costo_unitario', 12, 2);
            $table->decimal('descuento', 12, 2)->default(0); // RF-09, descuento de la línea
            $table->decimal('iva_porcentaje', 5, 2)->default(19);
            $table->boolean('sin_iva')->default(false); // RF-10
            $table->timestamps();
        });

        Schema::create('unidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referencia_id')->constrained('referencias')->restrictOnDelete();
            $table->foreignId('ingreso_item_id')->index()->constrained('ingreso_items')->restrictOnDelete();
            $table->string('codigo_barras', 32)->unique(); // RF-03
            $table->enum('estado', ['disponible', 'vendida'])->default('disponible');
            $table->timestamps();

            $table->index(['referencia_id', 'estado']);
        });

        Schema::create('ventas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->enum('estado', ['abierta', 'cerrada'])->default('abierta');
            $table->timestamp('cerrada_at')->nullable();
            $table->timestamps();

            $table->index(['usuario_id', 'estado']);
            $table->index('created_at');
        });

        // RF-06 / RF-12: sin UNIQUE en unidad_id, una unidad devuelta puede venderse de nuevo.
        Schema::create('venta_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->constrained('ventas')->restrictOnDelete();
            $table->foreignId('unidad_id')->index()->constrained('unidades')->restrictOnDelete();
            $table->enum('metodo_captura', ['escaneado', 'digitado']);
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        // RF-07: cabecera del reverso. motivo obligatorio.
        Schema::create('reversos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->index()->constrained('ventas')->restrictOnDelete();
            $table->enum('tipo', ['devolucion', 'anulacion']);
            $table->string('motivo', 500);
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        // RF-07: UNIQUE(venta_item_id) impide reversar dos veces el mismo ítem.
        Schema::create('reverso_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reverso_id')->index()->constrained('reversos')->restrictOnDelete();
            $table->foreignId('venta_item_id')->unique()->constrained('venta_items')->restrictOnDelete();
        });

        // Historial inmutable: una fila por unidad que entra, sale o vuelve.
        Schema::create('movimientos_inventario', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo', ['ingreso', 'venta', 'reverso']);
            $table->foreignId('unidad_id')->index()->constrained('unidades')->restrictOnDelete();
            $table->foreignId('referencia_id')->constrained('referencias')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->enum('metodo_captura', ['escaneado', 'digitado'])->nullable(); // RF-12
            $table->string('origen_type', 40);
            $table->unsignedBigInteger('origen_id');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['referencia_id', 'created_at']);
            $table->index(['origen_type', 'origen_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_inventario');
        Schema::dropIfExists('reverso_items');
        Schema::dropIfExists('reversos');
        Schema::dropIfExists('venta_items');
        Schema::dropIfExists('ventas');
        Schema::dropIfExists('unidades');
        Schema::dropIfExists('ingreso_items');
        Schema::dropIfExists('ingresos');
        Schema::dropIfExists('secuencias');
    }
};
