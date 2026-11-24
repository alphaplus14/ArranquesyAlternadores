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
 * S-25: codigo_fabricante = código de barras o QR del producto (opcional, único por proveedor).
 * S-26: `activo` tampoco es asignable en masa: solo lo cambia ReferenciaService (desactivar/reactivar).
 */
class Referencia extends Model
{
    use Auditable, HasFactory;

    protected $table = 'referencias';

    protected $fillable = [
        'codigo_referencia', 'codigo_fabricante', 'calidad', 'proveedor_id', 'categoria_id',
        'codigo_universal_id', 'descripcion', 'precio_venta', 'iva_venta_porcentaje',
        'stock_minimo', // RF-22: null = usa el general; 0 = no maneja mínimo
    ];

    protected function casts(): array
    {
        return [
            'calidad' => Calidad::class, // S-23: fino, genérico o null
            'precio_venta' => 'decimal:2',
            'iva_venta_porcentaje' => 'decimal:2', // RF-14 / S-15: 19, o 0 = sin IVA
            'existencia' => 'integer',
            'activo' => 'boolean',
            'stock_minimo' => 'integer',
        ];
    }

    protected $attributes = ['activo' => true];

    /** S-26 / RF-11: la creación también queda en auditoría (admin y logística crean). */
    protected function auditaCreacion(): bool
    {
        return true;
    }

    /** S-26: solo las referencias activas (listado, búsqueda, agotados, escaneo). */
    public function scopeActivas($query)
    {
        return $query->where('referencias.activo', true);
    }

    /**
     * S-31 / S-25: referencias cuyo código del proveedor o del fabricante es exactamente $codigo
     * (sin espacios en los extremos; la intercalación utf8mb4_unicode_ci ignora mayúsculas). Cada
     * columna tiene índice simple: MySQL resuelve el OR con index_merge (union), sin recorrer la tabla.
     */
    public function scopePorCodigoProveedor($query, string $codigo)
    {
        $codigo = trim($codigo);

        return $query->where(fn ($q) => $q->where('referencias.codigo_referencia', $codigo)->orWhere('referencias.codigo_fabricante', $codigo));
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
