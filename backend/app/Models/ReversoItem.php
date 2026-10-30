<?php

namespace App\Models;

use App\Models\Concerns\Inmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** RF-07 */
class ReversoItem extends Model
{
    use Inmutable;

    public $timestamps = false;

    protected $table = 'reverso_items';

    protected $fillable = ['reverso_id', 'venta_item_id'];

    public function reverso(): BelongsTo
    {
        return $this->belongsTo(Reverso::class);
    }

    public function ventaItem(): BelongsTo
    {
        return $this->belongsTo(VentaItem::class);
    }
}
