<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** RF-01 / RF-02 */
class CodigoUniversal extends Model
{
    use Auditable, HasFactory;

    protected $table = 'codigos_universales';

    protected $fillable = ['codigo', 'categoria_id', 'descripcion'];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function referencias(): HasMany
    {
        return $this->hasMany(Referencia::class);
    }

    /** RF-02: la existencia del universal es SUM(existencia) de sus referencias; nunca se guarda. */
    public function scopeConExistencia(Builder $query): Builder
    {
        return $query->withSum('referencias as existencia', 'existencia');
    }
}
