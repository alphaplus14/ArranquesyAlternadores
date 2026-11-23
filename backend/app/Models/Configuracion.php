<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Parámetros del negocio que edita el administrador (RF-22: `stock_minimo_general`).
 * Cada cambio queda en la auditoría (RF-11): tabla configuraciones, campo valor.
 */
class Configuracion extends Model
{
    use Auditable;

    public const STOCK_MINIMO_GENERAL = 'stock_minimo_general';

    protected $table = 'configuraciones';

    protected $fillable = ['clave', 'valor'];
}
