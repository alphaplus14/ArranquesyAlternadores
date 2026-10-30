<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** RF-05 / RF-09 / RF-10 */
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

    /** Subtotal de la línea después de su descuento (RF-09). */
    public function subtotal(): float
    {
        return round($this->cantidad * (float) $this->costo_unitario - (float) $this->descuento, 2);
    }

    /** RF-10: las líneas sin IVA no suman impuesto. */
    public function iva(): float
    {
        return $this->sin_iva ? 0.0 : round($this->subtotal() * (float) $this->iva_porcentaje / 100, 2);
    }
}
