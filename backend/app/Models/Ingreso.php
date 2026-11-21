<?php

namespace App\Models;

use App\Enums\EstadoIngreso;
use App\Enums\TipoIngreso;
use App\Exceptions\RegistroInmutableException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * RF-05 / RF-09. RF-04: remisión o factura, las dos digitadas a mano. Al confirmar queda quién
 * confirmó. diferencia_aceptada y diferencia_motivo se conservan para RF-19.
 * Confirmado, el ingreso ya no se edita ni se borra (sus unidades y movimientos dependen de él).
 */
class Ingreso extends Model
{
    protected $table = 'ingresos';

    protected $fillable = [
        'tipo', 'proveedor_id', 'numero_documento', 'fecha', 'descuento_total', 'usuario_id',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoIngreso::class,
            'estado' => EstadoIngreso::class,
            'fecha' => 'date',
            'descuento_total' => 'decimal:2',
            'diferencia_aceptada' => 'boolean',
            'confirmado_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Solo se permite el paso de borrador a confirmado; después, nada.
        static::updating(function (Ingreso $ingreso) {
            if ($ingreso->getOriginal('estado') === EstadoIngreso::Confirmado) {
                throw RegistroInmutableException::para($ingreso, 'editar un ingreso confirmado');
            }
        });

        static::deleting(function (Ingreso $ingreso) {
            if ($ingreso->getOriginal('estado') === EstadoIngreso::Confirmado) {
                throw RegistroInmutableException::para($ingreso, 'borrar un ingreso confirmado');
            }
        });
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /** Quien confirmó el ingreso. */
    public function confirmadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmado_por');
    }

    public function items(): HasMany
    {
        return $this->hasMany(IngresoItem::class);
    }

    /**
     * RF-09 / RF-10: total del documento con lo digitado = subtotal de líneas − descuento del
     * documento + IVA. Usa la relación items (cargarla antes para no repetir consultas).
     */
    public function totalCalculado(): float
    {
        $subtotal = $this->items->sum(fn (IngresoItem $i) => $i->subtotal());
        $iva = $this->items->sum(fn (IngresoItem $i) => $i->iva());

        return round($subtotal - (float) $this->descuento_total + $iva, 2);
    }

    /** RF-04: el número del documento se guarda recortado y con un solo espacio entre partes. */
    public static function normalizarNumero(string $numero): string
    {
        return preg_replace('/\s+/u', ' ', trim($numero));
    }

    public function esBorrador(): bool
    {
        return $this->estado === EstadoIngreso::Borrador;
    }
}
