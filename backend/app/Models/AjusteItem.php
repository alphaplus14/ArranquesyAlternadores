<?php

namespace App\Models;

use App\Enums\MetodoCaptura;
use App\Models\Concerns\Inmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** RF-21 / RF-12: una unidad del ajuste y cómo llegó su código. Inmutable. */
class AjusteItem extends Model
{
    use Inmutable;

    public $timestamps = false;

    protected $table = 'ajuste_items';

    protected $fillable = ['ajuste_id', 'unidad_id', 'metodo_captura'];

    protected function casts(): array
    {
        return ['metodo_captura' => MetodoCaptura::class];
    }

    public function ajuste(): BelongsTo
    {
        return $this->belongsTo(AjusteInventario::class, 'ajuste_id');
    }

    public function unidad(): BelongsTo
    {
        return $this->belongsTo(Unidad::class);
    }
}
