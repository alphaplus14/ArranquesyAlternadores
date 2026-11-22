<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * S-23: la empresa no maneja marcas. Las referencias de un mismo repuesto se distinguen por
 * código, proveedor y calidad. Se elimina referencias.marca_id (FK e índice incluidos).
 *
 * Antes de borrar se deja en el log (y en la salida de `migrate`) cuántas referencias tenía
 * cada marca, por si hubiera que recuperar esa información desde el respaldo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->registrarResumen();

        Schema::table('referencias', function (Blueprint $table) {
            $table->dropForeign(['marca_id']);
            $table->dropIndex(['marca_id']);
            $table->dropColumn('marca_id');
        });
    }

    /**
     * Devuelve la columna (nullable: el vínculo de cada referencia se perdió; se recupera del
     * respaldo de la base). Requiere que la tabla marcas exista (se revierte antes la 000300).
     */
    public function down(): void
    {
        Schema::table('referencias', function (Blueprint $table) {
            $table->foreignId('marca_id')->nullable()->after('calidad')->index()->constrained('marcas')->restrictOnDelete();
        });
    }

    private function registrarResumen(): void
    {
        $filas = DB::table('referencias')
            ->leftJoin('marcas', 'marcas.id', '=', 'referencias.marca_id')
            ->groupBy('referencias.marca_id', 'marcas.nombre')
            ->orderBy('marcas.nombre')
            ->get([
                'referencias.marca_id',
                DB::raw("COALESCE(marcas.nombre, '(sin marca)') as marca"),
                DB::raw('COUNT(*) as referencias'),
            ]);

        $lineas = $filas->map(fn ($f) => sprintf('  marca_id %s · %s: %d referencias', $f->marca_id ?? '—', $f->marca, $f->referencias));
        $resumen = "S-23: referencias por marca antes de eliminar referencias.marca_id\n"
            .($lineas->isEmpty() ? '  (no hay referencias)' : $lineas->implode("\n"));

        Log::info($resumen, ['referencias_por_marca' => $filas->toArray()]);

        if (! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln($resumen);
        }
    }
};
