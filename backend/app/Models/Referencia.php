<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * RF-01 / RF-02. `existencia` no es asignable en masa: solo la cambian los servicios de inventario.
 */
class Referencia extends Model
{
    use Auditable, HasFactory;

    protected $table = 'referencias';

    protected $fillable = [
        'codigo_referencia', 'marca_id', 'proveedor_id', 'categoria_id',
        'codigo_universal_id', 'descripcion', 'precio_venta',
    ];

    protected function casts(): array
    {
        return [
            'precio_venta' => 'decimal:2',
            'existencia' => 'integer',
        ];
    }

    public function marca(): BelongsTo
    {
        return $this->belongsTo(Marca::class);
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
