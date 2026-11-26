<?php

namespace App\Models;

use App\Enums\EstadoVenta;
use App\Enums\FormaPago;
use App\Enums\TipoDocumentoVenta;
use App\Exceptions\RegistroInmutableException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * RF-06 / RF-07 / RF-14. Nunca se borra. Las únicas ediciones permitidas son cerrarla (abierta a
 * cerrada, fijando en ese mismo cierre vence_el) y, S-46, descartarla (abierta a descartada, solo
 * con descartada_at y descartada_por) cuando no tiene ítems vigentes; eso lo decide VentaService.
 * forma_pago, cliente_id y factura_contapyme se fijan al crear.
 * S-73: una venta a crédito "de solo valor" (tipo_documento y valor_total no nulos) nace ya cerrada,
 * sin ítems ni movimientos de inventario; tipo y valor no se editan: se anula y se registra de nuevo.
 */
class Venta extends Model
{
    protected $table = 'ventas';

    protected $fillable = ['usuario_id', 'forma_pago', 'cliente_id', 'factura_contapyme', 'tipo_documento', 'valor_total'];

    protected $attributes = ['estado' => 'abierta', 'forma_pago' => 'contado'];

    protected function casts(): array
    {
        return [
            'estado' => EstadoVenta::class,
            'cerrada_at' => 'datetime',
            'descartada_at' => 'datetime',
            'forma_pago' => FormaPago::class,
            'vence_el' => 'date',
            'tipo_documento' => TipoDocumentoVenta::class, // S-73
            'valor_total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Venta $venta) {
            $desde = $venta->getOriginal('estado');
            $cambios = array_keys($venta->getDirty());
            $esCierre = $desde === EstadoVenta::Abierta
                && $venta->estado === EstadoVenta::Cerrada
                && array_diff($cambios, ['estado', 'cerrada_at', 'vence_el', 'updated_at']) === [];
            // S-46: abierta → descartada, sin tocar nada más.
            $esDescarte = $desde === EstadoVenta::Abierta
                && $venta->estado === EstadoVenta::Descartada
                && array_diff($cambios, ['estado', 'descartada_at', 'descartada_por', 'updated_at']) === [];

            if (! $esCierre && ! $esDescarte) {
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

    /** RF-24 (S-48): cómo se pagó la venta de contado (vacío en crédito y en las de contado anteriores). */
    public function pagos(): HasMany
    {
        return $this->hasMany(VentaPago::class);
    }

    /** S-73: registro de cartera sin ítems (cliente + documento + valor total con IVA). */
    public function esSoloValor(): bool
    {
        return $this->tipo_documento !== null && $this->valor_total !== null;
    }

    public function esCredito(): bool
    {
        return $this->forma_pago === FormaPago::Credito;
    }

    public function estaAbierta(): bool
    {
        return $this->estado === EstadoVenta::Abierta;
    }

    public function estaDescartada(): bool
    {
        return $this->estado === EstadoVenta::Descartada;
    }

    /**
     * S-46: por qué una venta que no está abierta no admite el cambio. Un solo texto para escanear,
     * cerrar, cambiar precios y reversar.
     */
    public function motivoNoAbierta(string $siCerrada = 'La venta está cerrada.'): string
    {
        return $this->estaDescartada()
            ? "La venta #{$this->id} fue descartada (no tenía ítems vigentes): ya no admite cambios. Escanee para empezar una venta nueva."
            : $siCerrada;
    }
}
