<?php

namespace App\Models;

use App\Enums\MetodoCaptura;
use App\Exceptions\RegistroInmutableException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * RF-06 / RF-12. RF-14: precio e IVA copiados de la referencia al escanear (S-10, S-15).
 * Inmutable, con una sola excepción (S-18): cambiar precio_unitario, y nada más, mientras su
 * venta es a crédito y está abierta. Ese cambio lo hace VentaService::cambiarPrecio, que lo audita.
 */
class VentaItem extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (VentaItem $item) {
            $soloPrecio = array_keys($item->getDirty()) === ['precio_unitario'];
            // La venta se lee de la base, no de la relación en memoria, para no confiar en un estado viejo.
            $venta = $soloPrecio ? Venta::query()->find($item->venta_id) : null;

            if (! ($venta?->esCredito() && $venta->estaAbierta())) {
                throw RegistroInmutableException::para($item, 'editar');
            }
        });

        static::deleting(function (VentaItem $item) {
            throw RegistroInmutableException::para($item, 'borrar');
        });
    }

    protected $table = 'venta_items';

    protected $fillable = ['venta_id', 'unidad_id', 'precio_unitario', 'iva_porcentaje', 'metodo_captura', 'usuario_id'];

    protected function casts(): array
    {
        return [
            'metodo_captura' => MetodoCaptura::class,
            'precio_unitario' => 'decimal:2',
            'iva_porcentaje' => 'decimal:2',
        ];
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function unidad(): BelongsTo
    {
        return $this->belongsTo(Unidad::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function reversoItem(): HasOne
    {
        return $this->hasOne(ReversoItem::class);
    }
}
