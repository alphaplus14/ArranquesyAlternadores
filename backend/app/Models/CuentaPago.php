<?php

namespace App\Models;

use App\Enums\TipoCuentaPago;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RF-24 (S-48): cuenta donde entra o sale el dinero (Efectivo, bancos, datáfono, billeteras). Solo el
 * admin la crea, edita y desactiva; nunca se borra (tiene pagos, abonos y movimientos). Auditable (RF-11).
 */
class CuentaPago extends Model
{
    use Auditable;

    protected $table = 'cuentas_pago';

    protected $fillable = ['nombre', 'tipo', 'activa', 'propietario_id'];

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

    public function propietario(): BelongsTo
    {
        return $this->belongsTo(Propietario::class, 'propietario_id');
    }

    /** Catálogo de cuentas (S-48, S-88): el dueño solo viene cargado en las billeteras que lo tienen. */
    public function aCatalogo(bool $conDueno = true): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'tipo' => $this->tipo,
            'activa' => $this->activa,
        ] + ($conDueno ? ['propietario' => $this->propietario === null ? null : [
            'id' => $this->propietario->id,
            'nombre' => $this->propietario->nombre,
        ]] : []);
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
