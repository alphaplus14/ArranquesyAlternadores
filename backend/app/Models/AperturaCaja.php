<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RF-25 (S-49): efectivo con que abre la caja un día. Lo registra el mostrador; se corrige mientras
 * el día no tenga cierre vigente. Una por fecha. Auditable: la creación y cada corrección quedan en
 * la auditoría (valor anterior, nuevo, usuario y fecha). No se borra.
 */
class AperturaCaja extends Model
{
    use Auditable;

    protected $table = 'aperturas_caja';

    protected $fillable = ['fecha', 'valor', 'usuario_id'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'valor' => 'decimal:2',
        ];
    }

    protected function auditaCreacion(): bool
    {
        return true;
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
