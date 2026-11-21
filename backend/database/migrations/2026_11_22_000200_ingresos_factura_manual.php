<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RF-04 (decisión del 22-nov-2026, S-19): la factura de compra ya no se lee por QR ni XML; se
 * ingresa a mano, igual que la remisión.
 *
 * - ingresos.tipo: `factura_qr` pasa a `factura`.
 * - Se eliminan cufe, subtotal_qr, iva_qr, total_qr y diferencia_qr.
 * - Se conservan confirmado_por, diferencia_aceptada y diferencia_motivo (RF-19 los usará).
 * - Índice único (proveedor_id, tipo, numero_documento): un documento no entra dos veces.
 *   Si los datos ya traen repetidos, la migración se detiene y los lista; no borra nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->cambiarTipo(['remision', 'factura_qr', 'factura']);
        DB::table('ingresos')->where('tipo', 'factura_qr')->update(['tipo' => 'factura']);
        $this->cambiarTipo(['remision', 'factura']);

        $repetidos = DB::table('ingresos')
            ->select('proveedor_id', 'tipo', 'numero_documento', DB::raw('GROUP_CONCAT(id ORDER BY id) AS ids'))
            ->groupBy('proveedor_id', 'tipo', 'numero_documento')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($repetidos->isNotEmpty()) {
            $detalle = $repetidos->map(fn ($r) => "proveedor {$r->proveedor_id}, {$r->tipo} {$r->numero_documento}: ingresos {$r->ids}")->implode('; ');

            throw new RuntimeException("Hay documentos repetidos; corríjalos a mano antes de migrar (no se borra nada): {$detalle}");
        }

        Schema::table('ingresos', function (Blueprint $table) {
            $table->dropUnique(['cufe']);
            $table->dropColumn(['cufe', 'subtotal_qr', 'iva_qr', 'total_qr', 'diferencia_qr']);
            $table->unique(['proveedor_id', 'tipo', 'numero_documento']);
        });
    }

    public function down(): void
    {
        Schema::table('ingresos', function (Blueprint $table) {
            // La llave foránea de proveedor_id sigue cubierta por el índice (proveedor_id, fecha).
            $table->dropUnique(['proveedor_id', 'tipo', 'numero_documento']);
            $table->string('cufe', 120)->nullable()->unique()->after('numero_documento');
            $table->decimal('subtotal_qr', 14, 2)->nullable()->after('descuento_total');
            $table->decimal('iva_qr', 14, 2)->nullable()->after('subtotal_qr');
            $table->decimal('total_qr', 14, 2)->nullable()->after('iva_qr');
            $table->decimal('diferencia_qr', 14, 2)->nullable()->after('total_qr');
        });

        $this->cambiarTipo(['remision', 'factura_qr', 'factura']);
        DB::table('ingresos')->where('tipo', 'factura')->update(['tipo' => 'factura_qr']);
        $this->cambiarTipo(['remision', 'factura_qr']);
    }

    /** @param list<string> $valores */
    private function cambiarTipo(array $valores): void
    {
        Schema::table('ingresos', function (Blueprint $table) use ($valores) {
            $table->enum('tipo', $valores)->default('remision')->change();
        });
    }
};
