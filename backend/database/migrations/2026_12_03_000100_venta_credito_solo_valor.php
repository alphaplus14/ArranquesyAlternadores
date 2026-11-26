<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RF-14 cambiado (S-73, S-74): la venta a crédito pasa a ser un registro de cartera SIN ítems:
 * cliente + tipo de documento (factura | remisión) + valor total con IVA incluido. El inventario no
 * baja con este registro (los productos se descargan aparte con una venta normal de mostrador).
 *
 * - ventas.tipo_documento y ventas.valor_total: un registro "de solo valor" es el que tiene los dos
 *   no nulos. Las ventas con ítems (de contado y las de crédito anteriores) los dejan en NULL. El
 *   número del documento, opcional, sigue en factura_contapyme (sin renombrar).
 * - reversos.valor: la anulación de un registro de solo valor no tiene reverso_items; guarda el monto
 *   anulado (= valor_total). En los reversos con ítems queda NULL (su valor sale de los ítems).
 * - Índice ventas(cliente_id, forma_pago, estado): saldo por cliente y validación del número. MySQL lo
 *   adopta como índice de la llave foránea cliente_id (y borra el automático): down() no lo quita.
 * - Índice ventas(forma_pago, estado, cerrada_at): las ventas a crédito cerradas de un período (panel,
 *   caja e informe), sin recorrer todas las de crédito.
 * Cada paso comprueba si ya está hecho (se puede volver a correr). Sin migración de datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ventas', 'tipo_documento')) {
            Schema::table('ventas', function (Blueprint $table) {
                $table->enum('tipo_documento', ['factura', 'remision'])->nullable()->after('factura_contapyme');
                $table->decimal('valor_total', 14, 2)->nullable()->after('tipo_documento');
            });
        }

        if (! Schema::hasIndex('ventas', ['cliente_id', 'forma_pago', 'estado'])) {
            Schema::table('ventas', fn (Blueprint $table) => $table->index(['cliente_id', 'forma_pago', 'estado'], 'ventas_cliente_forma_estado_index'));
        }

        if (! Schema::hasIndex('ventas', ['forma_pago', 'estado', 'cerrada_at'])) {
            Schema::table('ventas', fn (Blueprint $table) => $table->index(['forma_pago', 'estado', 'cerrada_at'], 'ventas_forma_estado_cerrada_index'));
        }

        if (! Schema::hasColumn('reversos', 'valor')) {
            Schema::table('reversos', function (Blueprint $table) {
                $table->decimal('valor', 14, 2)->nullable()->after('motivo');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('reversos', 'valor')) {
            Schema::table('reversos', fn (Blueprint $table) => $table->dropColumn('valor'));
        }
        if (Schema::hasIndex('ventas', 'ventas_forma_estado_cerrada_index')) {
            Schema::table('ventas', fn (Blueprint $table) => $table->dropIndex('ventas_forma_estado_cerrada_index'));
        }
        // ventas_cliente_forma_estado_index se queda: la llave foránea cliente_id lo usa.
        if (Schema::hasColumn('ventas', 'tipo_documento')) {
            Schema::table('ventas', fn (Blueprint $table) => $table->dropColumn(['tipo_documento', 'valor_total']));
        }
    }
};
