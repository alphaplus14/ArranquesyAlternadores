<?php

namespace App\Models;

use App\Models\Concerns\Inmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** RF-26 (S-53): anulación de un movimiento de caja, con motivo, usuario y fecha. Inmutable. */
class MovimientoCajaAnulacion extends Model
{
    use Inmutable;

    public const UPDATED_AT = null;

    protected $table = 'movimiento_caja_anulaciones';

    protected $fillable = ['movimiento_id', 'motivo', 'usuario_id'];

    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(MovimientoCaja::class, 'movimiento_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
