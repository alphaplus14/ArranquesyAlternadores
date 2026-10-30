<?php

namespace App\Models;

use App\Enums\MetodoCaptura;
use App\Models\Concerns\Inmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** RF-06 / RF-12 */
class VentaItem extends Model
{
    use Inmutable;

    public const UPDATED_AT = null;

    protected $table = 'venta_items';

    protected $fillable = ['venta_id', 'unidad_id', 'metodo_captura', 'usuario_id'];

    protected function casts(): array
    {
        return ['metodo_captura' => MetodoCaptura::class];
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function unidad(): BelongsTo
    {
        return $this->belongsTo(Unidad::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function reversoItem(): HasOne
    {
        return $this->hasOne(ReversoItem::class);
    }
}
