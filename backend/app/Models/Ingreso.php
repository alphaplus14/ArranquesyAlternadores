<?php

namespace App\Models;

use App\Enums\EstadoIngreso;
use App\Enums\FormaPago;
use App\Enums\TipoIngreso;
use App\Exceptions\RegistroInmutableException;
use App\Models\Concerns\Auditable;
use App\Support\TotalesIngreso;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * RF-05 / RF-09. RF-04: remisión o factura, las dos digitadas a mano. Al confirmar queda quién
 * confirmó y el total (RF-16). diferencia_aceptada y diferencia_motivo se conservan para RF-19.
 * RF-16: forma de pago y vencimiento digitado. RF-11: cada edición queda en la auditoría.
 * Confirmado, el ingreso ya no se edita ni se borra (sus unidades y movimientos dependen de él).
 */
class Ingreso extends Model
{
    use Auditable;

    protected $table = 'ingresos';

    protected $fillable = [
        'tipo', 'forma_pago', 'proveedor_id', 'numero_documento', 'fecha', 'vence_el',
        'descuento_documento', 'descuento_documento_porcentaje', 'usuario_id',
    ];

    protected $attributes = ['forma_pago' => 'contado'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoIngreso::class,
            'forma_pago' => FormaPago::class,
            'estado' => EstadoIngreso::class,
            'fecha' => 'date',
            'vence_el' => 'date',
            'descuento_documento' => 'decimal:2',
            'descuento_documento_porcentaje' => 'decimal:2',
            'total' => 'decimal:2',
            'diferencia_aceptada' => 'boolean',
            'confirmado_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Solo se permite el paso de borrador a confirmado; después, nada (ni total, forma de pago
        // ni vencimiento).
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
     * RF-09 (ajuste) / S-22: totales con el descuento del documento prorrateado antes del IVA.
     * Si el descuento se digitó en %, manda el % (los pesos se recalculan con las líneas).
     * Usa la relación items (cargarla antes para no repetir consultas). El cálculo vive solo en
     * TotalesIngreso.
     */
    public function totales(): TotalesIngreso
    {
        return TotalesIngreso::calcular($this->items, $this->descuento_documento, $this->descuento_documento_porcentaje);
    }

    /** Total del documento con lo digitado, redondeado al peso (ver TotalesIngreso). */
    public function totalCalculado(): string
    {
        return $this->totales()->total;
    }

    public function esCredito(): bool
    {
        return $this->forma_pago === FormaPago::Credito;
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
