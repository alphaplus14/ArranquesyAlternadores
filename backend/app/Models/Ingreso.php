<?php

namespace App\Models;

use App\Enums\EstadoIngreso;
use App\Enums\TipoIngreso;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** RF-05 / RF-09. RF-04: tipo factura_qr con CUFE único y totales leídos del QR. */
class Ingreso extends Model
{
    protected $table = 'ingresos';

    protected $fillable = [
        'tipo', 'proveedor_id', 'numero_documento', 'cufe', 'fecha', 'descuento_total',
        'subtotal_qr', 'iva_qr', 'total_qr', 'usuario_id',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoIngreso::class,
            'estado' => EstadoIngreso::class,
            'fecha' => 'date',
            'descuento_total' => 'decimal:2',
            'subtotal_qr' => 'decimal:2',
            'iva_qr' => 'decimal:2',
            'total_qr' => 'decimal:2',
            'confirmado_at' => 'datetime',
        ];
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
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

    /** RF-04: total digitado menos total del QR; null si el QR no trajo total. */
    public function diferenciaQr(): ?float
    {
        return $this->total_qr === null ? null : round($this->totalCalculado() - (float) $this->total_qr, 2);
    }

    public function esFacturaQr(): bool
    {
        return $this->tipo === TipoIngreso::FacturaQr;
    }

    public function esBorrador(): bool
    {
        return $this->estado === EstadoIngreso::Borrador;
    }
}
