<?php

namespace App\Models;

use App\Enums\EstadoVenta;
use App\Enums\FormaPago;
use App\Exceptions\RegistroInmutableException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * RF-06 / RF-07 / RF-14. Nunca se borra. La única edición permitida es cerrarla (abierta a
 * cerrada) y, en ese mismo cierre, fijar vence_el. forma_pago, cliente_id y factura_contapyme
 * se fijan al crear.
 */
class Venta extends Model
{
    protected $table = 'ventas';

    protected $fillable = ['usuario_id', 'forma_pago', 'cliente_id', 'factura_contapyme'];

    protected $attributes = ['estado' => 'abierta', 'forma_pago' => 'contado'];

    protected function casts(): array
    {
        return [
            'estado' => EstadoVenta::class,
            'cerrada_at' => 'datetime',
            'forma_pago' => FormaPago::class,
            'vence_el' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Venta $venta) {
            $otrosCambios = array_diff(array_keys($venta->getDirty()), ['estado', 'cerrada_at', 'vence_el', 'updated_at']);
            $esCierre = $venta->getOriginal('estado') === EstadoVenta::Abierta
                && $venta->estado === EstadoVenta::Cerrada
                && $otrosCambios === [];

            if (! $esCierre) {
                throw RegistroInmutableException::para($venta, 'editar');
            }
        });

        static::deleting(function (Venta $venta) {
            throw RegistroInmutableException::para($venta, 'borrar');
        });
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(VentaItem::class);
    }

    public function reversos(): HasMany
    {
        return $this->hasMany(Reverso::class);
    }

    public function esCredito(): bool
    {
        return $this->forma_pago === FormaPago::Credito;
    }

    public function estaAbierta(): bool
    {
        return $this->estado === EstadoVenta::Abierta;
    }
}
