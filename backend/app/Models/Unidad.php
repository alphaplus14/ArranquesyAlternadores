<?php

namespace App\Models;

use App\Enums\EstadoUnidad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** RF-03: una fila por unidad física, con su código de barras del local. */
class Unidad extends Model
{
    protected $table = 'unidades';

    // S-39: ingreso_item_id (compra) o ajuste_id (alta por inventario físico): uno de los dos.
    protected $fillable = ['referencia_id', 'ingreso_item_id', 'ajuste_id', 'codigo_barras', 'estado'];

    // S-31: etiqueta_impresa_at no es asignable en masa; la marca EtiquetaService al imprimir.

    protected function casts(): array
    {
        return ['estado' => EstadoUnidad::class, 'etiqueta_impresa_at' => 'datetime'];
    }

    public function referencia(): BelongsTo
    {
        return $this->belongsTo(Referencia::class);
    }

    public function ingresoItem(): BelongsTo
    {
        return $this->belongsTo(IngresoItem::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class);
    }

    public function estaDisponible(): bool
    {
        return $this->estado === EstadoUnidad::Disponible;
    }

    /** RF-21: salió del inventario por una baja; no se vende ni se descuenta. */
    public function estaRetirada(): bool
    {
        return $this->estado === EstadoUnidad::Retirada;
    }
}
