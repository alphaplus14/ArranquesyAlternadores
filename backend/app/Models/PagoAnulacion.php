<?php

namespace App\Models;

use App\Models\Concerns\Inmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** RF-17: anulación de un pago, con usuario, fecha y motivo. El pago original sigue visible. */
class PagoAnulacion extends Model
{
    use Inmutable;

    public const UPDATED_AT = null;

    protected $table = 'pago_anulaciones';

    protected $fillable = ['pago_id', 'motivo', 'usuario_id'];

    public function pago(): BelongsTo
    {
        return $this->belongsTo(PagoProveedor::class, 'pago_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
