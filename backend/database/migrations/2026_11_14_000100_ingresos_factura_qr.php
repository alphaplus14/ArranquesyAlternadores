<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RF-04 (decisión S-06, 14-nov-2026): se descarta el XML. El QR de la factura llena el
 * encabezado de un ingreso `factura_qr` y los ítems se digitan como en la remisión (RF-05).
 *
 * - ingresos.tipo: `factura_xml` pasa a `factura_qr`.
 * - Totales que trae el QR, para compararlos con lo digitado al confirmar.
 * - ingresos.cufe ya existe con índice único: un CUFE no entra dos veces.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->cambiarTipo(['remision', 'factura_xml', 'factura_qr']);
        DB::table('ingresos')->where('tipo', 'factura_xml')->update(['tipo' => 'factura_qr']);
        $this->cambiarTipo(['remision', 'factura_qr']);

        Schema::table('ingresos', function (Blueprint $table) {
            $table->decimal('subtotal_qr', 14, 2)->nullable()->after('descuento_total'); // ValFac
            $table->decimal('iva_qr', 14, 2)->nullable()->after('subtotal_qr');           // ValIva
            $table->decimal('total_qr', 14, 2)->nullable()->after('iva_qr');              // ValTolFac
        });
    }

    public function down(): void
    {
        Schema::table('ingresos', function (Blueprint $table) {
            $table->dropColumn(['subtotal_qr', 'iva_qr', 'total_qr']);
        });

        $this->cambiarTipo(['remision', 'factura_xml', 'factura_qr']);
        DB::table('ingresos')->where('tipo', 'factura_qr')->update(['tipo' => 'factura_xml']);
        $this->cambiarTipo(['remision', 'factura_xml']);
    }

    /** @param list<string> $valores */
    private function cambiarTipo(array $valores): void
    {
        Schema::table('ingresos', function (Blueprint $table) use ($valores) {
            $table->enum('tipo', $valores)->default('remision')->change();
        });
    }
};
