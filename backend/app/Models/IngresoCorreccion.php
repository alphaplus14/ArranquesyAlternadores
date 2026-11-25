<?php

namespace App\Models;

use App\Models\Concerns\Inmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RF-28 (S-58): corrección de un ingreso confirmado, con motivo, foto del antes y el después,
 * unidades creadas y retiradas y totales. Inmutable: una corrección equivocada se arregla con otra.
 */
class IngresoCorreccion extends Model
{
    use Inmutable;

    public const UPDATED_AT = null;

    /** origen_type de los movimientos de inventario que causa una corrección. */
    public const ORIGEN = 'correccion_ingreso';

    protected $table = 'ingreso_correcciones';

    protected $fillable = [
        'ingreso_id', 'usuario_id', 'motivo', 'antes', 'despues',
        'unidades_creadas', 'unidades_retiradas', 'total_anterior', 'total_nuevo',
    ];

    protected function casts(): array
    {
        return [
            'antes' => 'array',
            'despues' => 'array',
            'unidades_creadas' => 'integer',
            'unidades_retiradas' => 'integer',
            'total_anterior' => 'decimal:2',
            'total_nuevo' => 'decimal:2',
        ];
    }

    public function ingreso(): BelongsTo
    {
        return $this->belongsTo(Ingreso::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
