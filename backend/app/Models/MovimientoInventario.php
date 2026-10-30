<?php

namespace App\Models;

use App\Enums\MetodoCaptura;
use App\Enums\TipoMovimiento;
use App\Models\Concerns\Inmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Historial inmutable. origen: ingreso, venta o reverso (morph map en AppServiceProvider). */
class MovimientoInventario extends Model
{
    use Inmutable;

    public const UPDATED_AT = null;

    protected $table = 'movimientos_inventario';

    protected $fillable = [
        'tipo', 'unidad_id', 'referencia_id', 'usuario_id', 'metodo_captura', 'origen_type', 'origen_id',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimiento::class,
            'metodo_captura' => MetodoCaptura::class,
        ];
    }

    public function unidad(): BelongsTo
    {
        return $this->belongsTo(Unidad::class);
    }

    public function referencia(): BelongsTo
    {
        return $this->belongsTo(Referencia::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function origen(): MorphTo
    {
        return $this->morphTo();
    }
}
