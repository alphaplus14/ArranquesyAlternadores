<?php

namespace App\Models;

use App\Enums\FormaAbonoEmpleado;
use App\Enums\TipoMovimientoEmpleado;
use App\Models\Concerns\Inmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * RF-27 (S-55): préstamo o abono de un empleado. Inmutable (regla 8): un error se corrige
 * anulándolo (MovimientoEmpleadoAnulacion) y registrando otro.
 */
class MovimientoEmpleado extends Model
{
    use Inmutable;

    public const UPDATED_AT = null;

    protected $table = 'movimientos_empleado';

    protected $fillable = ['empleado_id', 'tipo', 'fecha', 'valor', 'forma', 'cuenta_pago_id', 'apertura', 'nota', 'usuario_id'];

    protected $attributes = ['apertura' => false];

    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimientoEmpleado::class,
            'forma' => FormaAbonoEmpleado::class,
            'fecha' => 'date:Y-m-d',
            'valor' => 'decimal:2',
            'apertura' => 'boolean',
        ];
    }

    /** Los que cuentan para el saldo: sin anulación. */
    public function scopeVigentes(Builder $q): Builder
    {
        return $q->whereDoesntHave('anulacion');
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
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
        return $this->hasOne(MovimientoEmpleadoAnulacion::class, 'movimiento_id');
    }
}
