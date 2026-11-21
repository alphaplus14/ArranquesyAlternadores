<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** RF-05 / RF-09 / RF-10. Sus subtotales e IVA se calculan en App\Support\TotalesIngreso. */
class IngresoItem extends Model
{
    protected $table = 'ingreso_items';

    protected $fillable = [
        'referencia_id', 'cantidad', 'costo_unitario', 'descuento', 'iva_porcentaje', 'sin_iva',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'costo_unitario' => 'decimal:2',
            'descuento' => 'decimal:2',
            'iva_porcentaje' => 'decimal:2',
            'sin_iva' => 'boolean',
        ];
    }

    public function ingreso(): BelongsTo
    {
        return $this->belongsTo(Ingreso::class);
    }

    public function referencia(): BelongsTo
    {
        return $this->belongsTo(Referencia::class);
    }

    public function unidades(): HasMany
    {
        return $this->hasMany(Unidad::class);
    }
}
