<?php

namespace App\Models;

use App\Enums\EstadoVenta;
use App\Exceptions\RegistroInmutableException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * RF-06 / RF-07. Nunca se borra. La única edición permitida es cerrarla (abierta a cerrada).
 */
class Venta extends Model
{
    protected $table = 'ventas';

    protected $fillable = ['usuario_id'];

    protected $attributes = ['estado' => 'abierta'];

    protected function casts(): array
    {
        return [
            'estado' => EstadoVenta::class,
            'cerrada_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Venta $venta) {
            $otrosCambios = array_diff(array_keys($venta->getDirty()), ['estado', 'cerrada_at', 'updated_at']);
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

    public function items(): HasMany
    {
        return $this->hasMany(VentaItem::class);
    }

    public function reversos(): HasMany
    {
        return $this->hasMany(Reverso::class);
    }

    public function estaAbierta(): bool
    {
        return $this->estado === EstadoVenta::Abierta;
    }
}
