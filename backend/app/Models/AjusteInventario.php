<?php

namespace App\Models;

use App\Enums\MotivoAjuste;
use App\Enums\TipoAjuste;
use App\Models\Concerns\Inmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** RF-21 (S-27): baja o reintegro de unidades, con motivo, nota, usuario y fecha. Inmutable. */
class AjusteInventario extends Model
{
    use Inmutable;

    public const UPDATED_AT = null;

    protected $table = 'ajustes_inventario';

    protected $fillable = ['tipo', 'motivo', 'nota', 'usuario_id'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoAjuste::class,
            'motivo' => MotivoAjuste::class,
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AjusteItem::class, 'ajuste_id');
    }
}
