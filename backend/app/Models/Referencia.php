<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
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
        'codigo_universal_id', 'descripcion', 'precio_venta', 'iva_venta_porcentaje',
    ];

    protected function casts(): array
    {
        return [
            'precio_venta' => 'decimal:2',
            'iva_venta_porcentaje' => 'decimal:2', // RF-14 / S-15: 19, o 0 = sin IVA
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

    /**
     * RF-12: búsqueda por descripción con el índice FULLTEXT, en modo booleano. Exige todas las
     * palabras, acepta el comienzo de cada una y ordena por relevancia.
     * Versión mínima: la definitiva llega con la búsqueda por texto de /api/referencias.
     */
    public function scopeBuscarTexto(Builder $query, string $q): Builder
    {
        $palabras = preg_split('/[^\p{L}\p{N}]+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
        if ($palabras === []) {
            return $query->whereRaw('1 = 0');
        }

        $expresion = implode(' ', array_map(fn (string $p) => "+{$p}*", $palabras));

        return $query
            ->whereFullText('descripcion', $expresion, ['mode' => 'boolean'])
            ->orderByRaw('MATCH(descripcion) AGAINST(? IN BOOLEAN MODE) DESC', [$expresion]);
    }
}
