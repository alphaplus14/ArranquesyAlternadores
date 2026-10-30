<?php

namespace App\Models;

use App\Enums\TipoReverso;
use App\Models\Concerns\Inmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** RF-07: movimiento nuevo ligado a la venta, con usuario, fecha y motivo. */
class Reverso extends Model
{
    use Inmutable;

    public const UPDATED_AT = null;

    protected $table = 'reversos';

    protected $fillable = ['venta_id', 'tipo', 'motivo', 'usuario_id'];

    protected function casts(): array
    {
        return ['tipo' => TipoReverso::class];
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReversoItem::class);
    }
}
