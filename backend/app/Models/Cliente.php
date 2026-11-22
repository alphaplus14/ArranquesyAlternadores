<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /** RF-14 */
    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class);
    }

    /** RF-15 */
    public function abonos(): HasMany
    {
        return $this->hasMany(AbonoCliente::class);
    }
}
