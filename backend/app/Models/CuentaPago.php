<?php

namespace App\Models;

use App\Enums\TipoCuentaPago;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * RF-24 (S-48): cuenta donde entra o sale el dinero (Efectivo, bancos, datáfono, billeteras). Solo el
 * admin la crea, edita y desactiva; nunca se borra (tiene pagos, abonos y movimientos). Auditable (RF-11).
 */
class CuentaPago extends Model
{
    use Auditable;

    protected $table = 'cuentas_pago';

    protected $fillable = ['nombre', 'tipo', 'activa'];

    protected $attributes = ['activa' => true];

    protected function casts(): array
    {
        return [
            'tipo' => TipoCuentaPago::class,
            'activa' => 'boolean',
        ];
    }

    protected function auditaCreacion(): bool
    {
        return true;
    }

    public function scopeActivas(Builder $q): Builder
    {
        return $q->where('activa', true);
    }

    public function esEfectivo(): bool
    {
        return $this->tipo === TipoCuentaPago::Efectivo;
    }

    /** La cuenta "Efectivo" que crea la migración (la primera de tipo efectivo). */
    public static function efectivo(): self
    {
        return self::query()->where('tipo', TipoCuentaPago::Efectivo->value)->orderBy('id')->firstOrFail();
    }
}
