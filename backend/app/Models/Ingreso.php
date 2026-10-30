<?php

namespace App\Models;

use App\Enums\EstadoIngreso;
use App\Enums\TipoIngreso;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** RF-05 / RF-09. RF-04 (tipo factura_xml, cufe) preparado, sin implementar. */
class Ingreso extends Model
{
    protected $table = 'ingresos';

    protected $fillable = [
        'tipo', 'proveedor_id', 'numero_documento', 'cufe', 'fecha', 'descuento_total', 'usuario_id',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoIngreso::class,
            'estado' => EstadoIngreso::class,
            'fecha' => 'date',
            'descuento_total' => 'decimal:2',
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

    public function esBorrador(): bool
    {
        return $this->estado === EstadoIngreso::Borrador;
    }
}
