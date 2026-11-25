<?php

namespace App\Models;

use App\Models\Concerns\Inmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** RF-25 (S-53): reapertura de un cierre de caja (solo admin, motivo obligatorio). Inmutable. */
class CierreReapertura extends Model
{
    use Inmutable;

    public const UPDATED_AT = null;

    protected $table = 'cierre_reaperturas';

    protected $fillable = ['cierre_id', 'motivo', 'usuario_id'];

    public function cierre(): BelongsTo
    {
        return $this->belongsTo(CierreCaja::class, 'cierre_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
