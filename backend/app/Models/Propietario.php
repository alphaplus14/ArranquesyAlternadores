<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * S-88 (RF-24): persona dueña de una o varias billeteras (Nequi de Dayana, Daviplata de Dayana).
 * Solo el admin la crea, renombra y desactiva; nunca se borra. Auditable (RF-11).
 */
class Propietario extends Model
{
    use Auditable;

    protected $table = 'propietarios';

    protected $fillable = ['nombre', 'activo'];

    protected $attributes = ['activo' => true];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    protected function auditaCreacion(): bool
    {
        return true;
    }

    public function cuentas(): HasMany
    {
        return $this->hasMany(CuentaPago::class, 'propietario_id');
    }
}
