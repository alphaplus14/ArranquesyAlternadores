<?php

namespace App\Models;

use App\Models\Concerns\Inmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RF-24 (S-48): una parte del pago de una venta de contado (cuenta y valor). Se crea al cerrar la
 * venta, en la misma transacción, y nunca se edita ni se borra.
 */
class VentaPago extends Model
{
    use Inmutable;

    public const UPDATED_AT = null;

    protected $table = 'venta_pagos';

    protected $fillable = ['venta_id', 'cuenta_pago_id', 'valor', 'referencia'];

    protected function casts(): array
    {
        return ['valor' => 'decimal:2'];
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaPago::class, 'cuenta_pago_id');
    }
}
