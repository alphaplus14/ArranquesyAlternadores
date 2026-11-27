<?php

namespace App\Models;

use App\Enums\MedioPago;
use App\Models\Concerns\Inmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * RF-15 (S-30): abono de un cliente. Nunca se edita ni se borra; anularlo es un movimiento nuevo
 * (AbonoAnulacion). No toca existencias.
 * S-32 / S-79: con venta_id, dirigido a esa venta a crédito (obligatorio desde S-79); NULL solo en
 * abonos generales anteriores a S-79, que se siguen leyendo FIFO. El
 * vínculo no se edita: corregirlo es anular el abono y registrar otro.
 */
class AbonoCliente extends Model
{
    use Inmutable;

    public const UPDATED_AT = null;

    protected $table = 'abonos_clientes';

    protected $fillable = ['cliente_id', 'venta_id', 'fecha', 'valor', 'medio', 'cuenta_pago_id', 'referencia', 'nota', 'usuario_id'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'valor' => 'decimal:2',
            'medio' => MedioPago::class,
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /** S-32: la venta a la que va dirigido (null = general). */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    /**
     * S-32: "Venta #11 · FE-1042" (o "Venta #11" sin factura de Contapyme) o "General". Usa la
     * relación venta si está cargada; si no, solo el número.
     */
    public function aplicadoA(): string
    {
        if ($this->venta_id === null) {
            return 'General';
        }

        $factura = $this->relationLoaded('venta') ? $this->venta?->factura_contapyme : null;

        return "Venta #{$this->venta_id}".($factura ? " · {$factura}" : '');
    }

    /** RF-24 (S-48): cuenta donde entró o salió el dinero (NULL en los registros anteriores). */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaPago::class, 'cuenta_pago_id');
    }

    public function anulacion(): HasOne
    {
        return $this->hasOne(AbonoAnulacion::class, 'abono_id');
    }
}
