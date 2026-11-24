<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * RF-05 / RF-09 / RF-10 / S-22: descuento de línea en % e IVA por línea (0 % = sin IVA).
 * Sus netos, bases e IVA se calculan en App\Support\TotalesIngreso. RF-11: cada edición se audita.
 */
class IngresoItem extends Model
{
    use Auditable;

    protected $table = 'ingreso_items';

    protected $fillable = [
        'referencia_id', 'cantidad', 'costo_unitario', 'descuento_porcentaje', 'iva_porcentaje',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'cantidad_cubierta' => 'integer', // RF-19: se fija al confirmar una factura ligada
            'costo_unitario' => 'decimal:2',
            'descuento_porcentaje' => 'decimal:2',
            'iva_porcentaje' => 'decimal:2',
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
