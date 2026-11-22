<?php

namespace App\Models;

use App\Enums\Calidad;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * RF-01 / RF-02. `existencia` no es asignable en masa: solo la cambian los servicios de inventario.
 * S-23: no hay marcas; las referencias de un repuesto se distinguen por código, proveedor y calidad.
 */
class Referencia extends Model
{
    use Auditable, HasFactory;

    protected $table = 'referencias';

    protected $fillable = [
        'codigo_referencia', 'calidad', 'proveedor_id', 'categoria_id',
        'codigo_universal_id', 'descripcion', 'precio_venta', 'iva_venta_porcentaje',
    ];

    protected function casts(): array
    {
        return [
            'calidad' => Calidad::class, // S-23: fino, genérico o null
            'precio_venta' => 'decimal:2',
            'iva_venta_porcentaje' => 'decimal:2', // RF-14 / S-15: 19, o 0 = sin IVA
            'existencia' => 'integer',
        ];
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function codigoUniversal(): BelongsTo
    {
        return $this->belongsTo(CodigoUniversal::class);
    }

    public function unidades(): HasMany
    {
        return $this->hasMany(Unidad::class);
    }
}
