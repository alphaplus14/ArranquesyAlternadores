<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** RF-01. S-24: logística y admin la crean (logística, siempre sin código universal). */
class Categoria extends Model
{
    use Auditable, HasFactory;

    protected $table = 'categorias';

    protected $fillable = ['nombre', 'admite_codigo_universal'];

    protected function casts(): array
    {
        return ['admite_codigo_universal' => 'boolean'];
    }

    /** S-24 / RF-11: la creación también queda en auditoría (logística puede crear). */
    protected function auditaCreacion(): bool
    {
        return true;
    }

    public function referencias(): HasMany
    {
        return $this->hasMany(Referencia::class);
    }
}
