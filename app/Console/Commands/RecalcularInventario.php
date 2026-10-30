<?php

namespace App\Console\Commands;

use App\Models\Auditoria;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * RF-02: compara el contador referencias.existencia contra COUNT(unidades disponibles).
 * Supuesto S-01: si algún día existe un modo "referencia + cantidad", esas referencias deben
 * excluirse de esta comparación.
 */
class RecalcularInventario extends Command
{
    protected $signature = 'inventario:recalcular {--corregir : Ajusta el contador y deja el ajuste en la auditoría}';

    protected $description = 'Reporta referencias cuyo contador de existencia no coincide con sus unidades disponibles';

    public function handle(): int
    {
        $diferencias = DB::table('referencias as r')
            ->leftJoinSub(
                DB::table('unidades')->where('estado', 'disponible')
                    ->groupBy('referencia_id')
                    ->select('referencia_id', DB::raw('COUNT(*) as disponibles')),
                'u', 'u.referencia_id', '=', 'r.id',
            )
            ->whereRaw('r.existencia <> COALESCE(u.disponibles, 0)')
            ->orderBy('r.id')
            ->get(['r.id', 'r.codigo_referencia', 'r.existencia', DB::raw('COALESCE(u.disponibles, 0) as disponibles')]);

        if ($diferencias->isEmpty()) {
            $this->info('Sin diferencias: todos los contadores coinciden con las unidades disponibles.');

            return self::SUCCESS;
        }

        $this->table(
            ['referencia_id', 'codigo_referencia', 'contador', 'unidades disponibles'],
            $diferencias->map(fn ($d) => [$d->id, $d->codigo_referencia, $d->existencia, $d->disponibles]),
        );

        if ($this->option('corregir')) {
            DB::transaction(function () use ($diferencias) {
                foreach ($diferencias as $d) {
                    DB::table('referencias')->where('id', $d->id)->update(['existencia' => $d->disponibles]);
                    Auditoria::create([
                        'tabla' => 'referencias',
                        'registro_id' => $d->id,
                        'campo' => 'existencia',
                        'valor_anterior' => (string) $d->existencia,
                        'valor_nuevo' => (string) $d->disponibles,
                        'usuario_id' => null, // ajuste de sistema por inventario:recalcular
                    ]);
                }
            });
            $this->warn("Se corrigieron {$diferencias->count()} referencias.");
        }

        return self::FAILURE;
    }
}
