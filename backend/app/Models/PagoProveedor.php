<?php

namespace App\Models;

use App\Models\Concerns\Inmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** RF-17: pago a un proveedor. Nunca se edita ni se borra; se anula con PagoAnulacion. */
class PagoProveedor extends Model
{
    use Inmutable;

    public const UPDATED_AT = null;

    protected $table = 'pagos_proveedores';

    protected $fillable = ['proveedor_id', 'ingreso_id', 'fecha', 'valor', 'medio', 'referencia', 'nota', 'usuario_id'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'valor' => 'decimal:2',
        ];
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    /** RF-17 (ajuste): el ingreso a crédito al que se aplica el pago; null = FIFO. */
    public function ingreso(): BelongsTo
    {
        return $this->belongsTo(Ingreso::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function anulacion(): HasOne
    {
        return $this->hasOne(PagoAnulacion::class, 'pago_id');
    }
}
