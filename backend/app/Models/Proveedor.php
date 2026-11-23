<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** RF-08 / RF-16. S-24: logística y admin lo crean; editar y borrar es del admin. */
class Proveedor extends Model
{
    use Auditable, HasFactory;

    protected $table = 'proveedores';

    /** S-29: asesor = nombre del asesor comercial (opcional). */
    protected $fillable = ['nombre', 'nit', 'asesor', 'plazo_dias'];

    protected function casts(): array
    {
        return ['plazo_dias' => 'integer'];
    }

    /** S-24 / RF-11: la creación también queda en auditoría (logística puede crear). */
    protected function auditaCreacion(): bool
    {
        return true;
    }

    /** NIT sin puntos, guiones ni espacios: "900.123.456-7" y "9001234567" son el mismo (S-24). */
    public static function normalizarNit(?string $nit): string
    {
        return preg_replace('/[.\-\s]/u', '', mb_strtolower(trim((string) $nit)));
    }

    public function referencias(): HasMany
    {
        return $this->hasMany(Referencia::class);
    }
}
