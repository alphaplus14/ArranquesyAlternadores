<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RF-24 (S-48): cuentas donde entra o sale el dinero del mostrador y pago de la venta de contado.
 *
 * - cuentas_pago: catálogo del admin (Auditable). Nunca se borra: se desactiva. La migración crea
 *   "Efectivo" (tipo efectivo), que la caja necesita siempre; el resto lo agrega el seeder.
 * - venta_pagos: cómo se pagó una venta de contado (una fila por cuenta; pago dividido = varias).
 *   Inmutable. Las ventas de contado cerradas antes de este cambio no tienen filas: la caja las
 *   cuenta como efectivo "sin detalle de pago".
 * - abonos_clientes y pagos_proveedores: + cuenta_pago_id (nullable para los registros viejos).
 *   `medio` se conserva y se deriva del tipo de la cuenta. Índice (cuenta_pago_id, created_at) para
 *   la caja del día (RF-25), que agrupa por cuenta y día de registro.
 * - reversos, abono_anulaciones y pago_anulaciones: + índice created_at; ventas: + (estado,
 *   cerrada_at). La caja del día filtra por esas fechas con una consulta agrupada por origen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas_pago', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 60)->unique();
            $table->enum('tipo', ['efectivo', 'banco', 'datafono', 'billetera']);
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });

        DB::table('cuentas_pago')->insert([
            'nombre' => 'Efectivo',
            'tipo' => 'efectivo',
            'activa' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('venta_pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->index()->constrained('ventas')->restrictOnDelete();
            $table->foreignId('cuenta_pago_id')->constrained('cuentas_pago')->restrictOnDelete();
            $table->decimal('valor', 14, 2);
            $table->string('referencia', 60)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['cuenta_pago_id', 'created_at']);
        });
        DB::statement('ALTER TABLE venta_pagos ADD CONSTRAINT venta_pagos_valor_positivo CHECK (valor > 0)');

        foreach (['abonos_clientes', 'pagos_proveedores'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->foreignId('cuenta_pago_id')->nullable()->after('medio')->constrained('cuentas_pago')->restrictOnDelete();
                $table->index(['cuenta_pago_id', 'created_at']);
                $table->index('created_at');
            });
        }

        foreach (['reversos', 'abono_anulaciones', 'pago_anulaciones'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->index('created_at');
            });
        }

        Schema::table('ventas', function (Blueprint $table) {
            $table->index(['estado', 'cerrada_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropIndex(['estado', 'cerrada_at']);
        });

        foreach (['reversos', 'abono_anulaciones', 'pago_anulaciones'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropIndex(['created_at']);
            });
        }

        foreach (['abonos_clientes', 'pagos_proveedores'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropForeign(['cuenta_pago_id']);
                $table->dropIndex(['created_at']);
                $table->dropIndex(['cuenta_pago_id', 'created_at']);
                $table->dropColumn('cuenta_pago_id');
            });
        }

        Schema::dropIfExists('venta_pagos');
        Schema::dropIfExists('cuentas_pago');
    }
};
