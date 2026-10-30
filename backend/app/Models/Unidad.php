<?php

namespace App\Models;

use App\Enums\EstadoUnidad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** RF-03: una fila por unidad física, con su código de barras del local. */
class Unidad extends Model
{
    protected $table = 'unidades';

    protected $fillable = ['referencia_id', 'ingreso_item_id', 'codigo_barras', 'estado'];

    protected function casts(): array
    {
        return ['estado' => EstadoUnidad::class];
    }

    public function referencia(): BelongsTo
    {
        return $this->belongsTo(Referencia::class);
    }

    public function ingresoItem(): BelongsTo
    {
        return $this->belongsTo(IngresoItem::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class);
    }

    public function estaDisponible(): bool
    {
        return $this->estado === EstadoUnidad::Disponible;
    }
}
