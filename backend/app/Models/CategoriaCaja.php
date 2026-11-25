<?php

namespace App\Models;

use App\Enums\GrupoCaja;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** RF-26 (S-51): categoría de un movimiento de caja, por grupo. Solo el admin la crea y edita; no se borra. */
class CategoriaCaja extends Model
{
    use Auditable;

    protected $table = 'categorias_caja';

    protected $fillable = ['nombre', 'grupo', 'activa'];

    protected $attributes = ['activa' => true];

    protected function casts(): array
    {
        return [
            'grupo' => GrupoCaja::class,
            'activa' => 'boolean',
        ];
    }

    protected function auditaCreacion(): bool
    {
        return true;
    }
}
