<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RF-25 (S-49, S-84): apertura MANUAL de la caja de un día. Desde S-84 la apertura es automática (el
 * efectivo contado del último cierre vigente anterior); esta fila solo existe cuando el mostrador la
 * cambia (con motivo si difiere de la del cierre anterior) o en el primer día sin cierres. Una por
 * fecha; se cambia mientras el día no tenga cierre vigente. Auditable: la creación y cada cambio
 * quedan en la auditoría (valor y motivo anterior y nuevo, usuario y fecha). No se borra.
 */
class AperturaCaja extends Model
{
    use Auditable;

    protected $table = 'aperturas_caja';

    protected $fillable = ['fecha', 'valor', 'motivo', 'usuario_id'];

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
