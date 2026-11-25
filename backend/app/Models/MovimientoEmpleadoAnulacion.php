<?php

namespace App\Models;

use App\Models\Concerns\Inmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** RF-27 (S-55): anulación de un préstamo o abono de empleado, con motivo, usuario y fecha. Inmutable. */
class MovimientoEmpleadoAnulacion extends Model
{
    use Inmutable;

    public const UPDATED_AT = null;

    protected $table = 'movimiento_empleado_anulaciones';

    protected $fillable = ['movimiento_id', 'motivo', 'usuario_id'];

    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(MovimientoEmpleado::class, 'movimiento_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
