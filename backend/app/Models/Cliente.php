<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** RF-13. Auditado (RF-11). No se borra: se desactiva. */
class Cliente extends Model
{
    use Auditable, HasFactory;

    protected $table = 'clientes';

    protected $fillable = ['nombre', 'documento', 'telefono', 'plazo_dias', 'activo'];

    protected $attributes = ['plazo_dias' => 30, 'activo' => true];

    protected function casts(): array
    {
        return [
            'plazo_dias' => 'integer',
            'activo' => 'boolean',
        ];
    }
}
