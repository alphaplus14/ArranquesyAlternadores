<?php

namespace App\Models;

use App\Models\Concerns\Inmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** RF-15: abono de un cliente. Nunca se edita ni se borra; se anula con AbonoAnulacion. */
class AbonoCliente extends Model
{
    use Inmutable;

    public const UPDATED_AT = null;

    protected $table = 'abonos_clientes';

    protected $fillable = ['cliente_id', 'fecha', 'valor', 'medio', 'referencia', 'nota', 'usuario_id'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'valor' => 'decimal:2',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function anulacion(): HasOne
    {
        return $this->hasOne(AbonoAnulacion::class, 'abono_id');
    }
}
