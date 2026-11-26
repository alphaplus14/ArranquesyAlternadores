<?php

namespace App\Models;

use App\Enums\TipoDocumentoVenta;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\Inmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * RF-25 / RF-26 (S-76): recaudo de una factura o remisión a crédito de Contapyme que no está en las
 * ventas a crédito del sistema. Entra a la caja del día (cuenta de pago) y nada más: no es venta, no
 * mueve inventario ni baja saldos de clientes. Inmutable: un error se anula con motivo y se registra otro.
 */
class RecaudoDocumento extends Model
{
    use Auditable, Inmutable;

    protected $table = 'recaudos_documento';

    protected $fillable = ['fecha', 'tipo_documento', 'numero_documento', 'valor', 'cuenta_pago_id', 'observacion', 'usuario_id'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'tipo_documento' => TipoDocumentoVenta::class,
            'valor' => 'decimal:2',
        ];
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaPago::class, 'cuenta_pago_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function anulacion(): HasOne
    {
        return $this->hasOne(RecaudoDocumentoAnulacion::class, 'recaudo_id');
    }
}
