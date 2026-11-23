<?php

namespace App\Models;

use App\Models\Concerns\Inmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** RF-15 (S-30): anulación de un abono, con motivo, usuario y fecha. Inmutable. */
class AbonoAnulacion extends Model
{
    use Inmutable;

    public const UPDATED_AT = null;

    protected $table = 'abono_anulaciones';

    protected $fillable = ['abono_id', 'motivo', 'usuario_id'];

    public function abono(): BelongsTo
    {
        return $this->belongsTo(AbonoCliente::class, 'abono_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
