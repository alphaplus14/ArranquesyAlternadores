<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Parámetros del negocio que edita el administrador (RF-22: `stock_minimo_general`; S-45:
 * `dias_sin_movimiento` del panel).
 * Cada cambio queda en la auditoría (RF-11): tabla configuraciones, campo valor.
 */
class Configuracion extends Model
{
    use Auditable;

    public const STOCK_MINIMO_GENERAL = 'stock_minimo_general';

    /** S-45 e: días sin venta para considerar estancada una referencia (120 si no hay valor). */
    public const DIAS_SIN_MOVIMIENTO = 'dias_sin_movimiento';

    /** RF-25 (S-51): meta de venta diaria en pesos (config caja.meta_venta_diaria si no hay valor). */
    public const META_VENTA_DIARIA = 'meta_venta_diaria';

    /** S-98: base fija de caja (no cuenta para el cuadre; queda en el cajón). */
    public const BASE_CAJA = 'base_caja';

    protected $table = 'configuraciones';

    protected $fillable = ['clave', 'valor'];
}
