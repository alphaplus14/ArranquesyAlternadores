<?php

namespace App\Models;

use App\Models\Concerns\Inmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * RF-25 (S-49, S-53): cierre de caja de un día, con los valores calculados al cerrarlo. Inmutable.
 * Desde la apertura diaria: efectivo_esperado = apertura + efectivo neto del día; saldo_anterior
 * repite la apertura y saldo_acumulado el efectivo esperado (columnas viejas, que en los cierres
 * anteriores guardan el saldo encadenado de antes y no se reescriben; apertura es NULL en esos).
 * El vigente de una fecha es el que no tiene reapertura (scope vigentes).
 * S-98: `base` es la base de caja vigente al cerrar (0 en los cierres anteriores). efectivo_contado es
 * todo el cajón (base incluida); diferencia = (contado − base) − esperado; el arrastre al día
 * siguiente es max(contado − base, 0) (arrastre()).
 */
class CierreCaja extends Model
{
    use Inmutable;

    public $timestamps = false;

    protected $table = 'cierres_caja';

    protected $fillable = [
        'fecha', 'apertura', 'base', 'efectivo_contado', 'efectivo_esperado', 'diferencia', 'saldo_anterior',
        'saldo_acumulado', 'observacion', 'usuario_id', 'cerrado_at',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'apertura' => 'decimal:2',
            'base' => 'decimal:2',
            'efectivo_contado' => 'decimal:2',
            'efectivo_esperado' => 'decimal:2',
            'diferencia' => 'decimal:2',
            'saldo_anterior' => 'decimal:2',
            'saldo_acumulado' => 'decimal:2',
            'cerrado_at' => 'datetime',
        ];
    }

    /** S-98: efectivo para el cuadre = contado − base (puede ser negativo si faltó la base). */
    public function paraCuadre(): string
    {
        return bcsub((string) $this->efectivo_contado, (string) ($this->base ?? 0), 2);
    }

    /** S-98: lo que este cierre deja como apertura del día siguiente: max(contado − base, 0). */
    public function arrastre(): string
    {
        $resto = $this->paraCuadre();

        return bccomp($resto, '0', 2) < 0 ? '0.00' : $resto;
    }

    public function scopeVigentes(Builder $q): Builder
    {
        return $q->whereDoesntHave('reapertura');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function reapertura(): HasOne
    {
        return $this->hasOne(CierreReapertura::class, 'cierre_id');
    }
}
