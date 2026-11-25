<?php

namespace App\Models;

use App\Enums\GrupoCaja;
use App\Models\Concerns\Inmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * RF-26: gasto, gasto fijo, cuota bancaria, ingreso externo u otro ingreso del día. Inmutable: un
 * error se corrige anulándolo (MovimientoCajaAnulacion) y registrando otro.
 */
class MovimientoCaja extends Model
{
    use Inmutable;

    public const UPDATED_AT = null;

    protected $table = 'movimientos_caja';

    protected $fillable = ['fecha', 'categoria_caja_id', 'grupo', 'concepto', 'valor', 'cuenta_pago_id', 'referencia', 'usuario_id'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'grupo' => GrupoCaja::class,
            'valor' => 'decimal:2',
        ];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaCaja::class, 'categoria_caja_id');
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaPago::class, 'cuenta_pago_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function anulacion(): HasOne
    {
        return $this->hasOne(MovimientoCajaAnulacion::class, 'movimiento_id');
    }
}
