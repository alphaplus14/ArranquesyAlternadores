<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * RF-27 (S-55): empleado con cuenta de préstamos y abonos. Auditado (RF-11). No se borra: se
 * desactiva, y no con saldo distinto de 0 (lo impone el servicio). No se enlaza con `users`.
 */
class Empleado extends Model
{
    use Auditable;

    protected $table = 'empleados';

    protected $fillable = ['nombre', 'documento', 'activo'];

    protected $attributes = ['activo' => true];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    protected function auditaCreacion(): bool
    {
        return true;
    }

    public function scopeActivos(Builder $q): Builder
    {
        return $q->where('activo', true);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoEmpleado::class);
    }
}
