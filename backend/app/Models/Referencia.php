<?php

namespace App\Models;

use App\Enums\Calidad;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * RF-01 / RF-02. `existencia` no es asignable en masa: solo la cambian los servicios de inventario.
 * S-23: no hay marcas; las referencias de un repuesto se distinguen por código, proveedor y calidad.
 * S-25: codigo_fabricante = código de barras o QR del producto (opcional, único por proveedor).
 * S-26: `activo` tampoco es asignable en masa: solo lo cambia ReferenciaService (desactivar/reactivar).
 */
class Referencia extends Model
{
    use Auditable, HasFactory;

    protected $table = 'referencias';

    protected $fillable = [
        'codigo_referencia', 'codigo_fabricante', 'calidad', 'proveedor_id', 'categoria_id',
        'codigo_universal_id', 'descripcion', 'precio_venta', 'iva_venta_porcentaje',
        'stock_minimo', // RF-22: null = usa el general; 0 = no maneja mínimo
    ];

    /** InnoDB no indexa palabras más cortas que innodb_ft_min_token_size (3 por defecto). */
    private const LARGO_MINIMO_FULLTEXT = 3;

    /**
     * Lista de stopwords por defecto de InnoDB (INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD).
     * No están en el índice: exigirlas con FULLTEXT dejaría por fuera filas que sí las tienen.
     */
    private const STOPWORDS_INNODB = [
        'a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how',
        'i', 'in', 'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what',
        'when', 'where', 'who', 'will', 'with', 'und', 'www',
    ];

    protected function casts(): array
    {
        return [
            'calidad' => Calidad::class, // S-23: fino, genérico o null
            'precio_venta' => 'decimal:2',
            'iva_venta_porcentaje' => 'decimal:2', // RF-14 / S-15: 19, o 0 = sin IVA
            'existencia' => 'integer',
            'activo' => 'boolean',
            'stock_minimo' => 'integer',
        ];
    }

    protected $attributes = ['activo' => true];

    /** S-26 / RF-11: la creación también queda en auditoría (admin y logística crean). */
    protected function auditaCreacion(): bool
    {
        return true;
    }

    /** S-26: solo las referencias activas (listado, búsqueda, agotados, escaneo). */
    public function scopeActivas($query)
    {
        return $query->where('referencias.activo', true);
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function codigoUniversal(): BelongsTo
    {
        return $this->belongsTo(CodigoUniversal::class);
    }

    public function unidades(): HasMany
    {
        return $this->hasMany(Unidad::class);
    }

    /**
     * RF-12 / búsqueda: prefijo del código de referencia (usa el índice) o descripción que
     * contenga TODAS las palabras, cada una como comienzo de palabra (FULLTEXT en modo booleano,
     * "+alternador* +hilux*"). Ordena primero lo que coincide por código y luego por relevancia.
     *
     * - Los operadores del modo booleano (+ - * " ( ) < > ~ @ …) se descartan: solo quedan letras
     *   y dígitos. Mayúsculas y tildes no importan (collation utf8mb4_unicode_ci).
     * - Las stopwords de InnoDB («de», «la», «en»…) no están en el índice y son relleno: se
     *   ignoran ("motor de arranque" = "motor arranque"), salvo que la búsqueda solo tenga eso.
     * - Las palabras de menos de 3 letras tampoco están en el índice ("mazda 6", "b 2600"): se
     *   exigen como comienzo de palabra con REGEXP '\bpalabra' sobre las filas que ya filtró el
     *   FULLTEXT (un LIKE '%6%' también traería "B 2600").
     */
    public function scopeBuscarTexto(Builder $query, string $q): Builder
    {
        $q = trim($q);
        if ($q === '') {
            return $query;
        }

        $palabras = array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q)) ?: [],
            fn (string $p) => $p !== '',
        )));
        $sinStopwords = array_diff($palabras, self::STOPWORDS_INNODB);
        $palabras = $sinStopwords !== [] ? array_values($sinStopwords) : $palabras;
        $indexables = array_filter($palabras, fn (string $p) => mb_strlen($p) >= self::LARGO_MINIMO_FULLTEXT
            && ! in_array($p, self::STOPWORDS_INNODB, true));
        $fueraDelIndice = array_diff($palabras, $indexables);
        $booleana = implode(' ', array_map(fn (string $p) => '+'.$p.'*', $indexables));

        $prefijoCodigo = addcslashes($q, '%_\\').'%';
        $match = 'MATCH(referencias.descripcion) AGAINST(? IN BOOLEAN MODE)';

        $query->where(function (Builder $w) use ($prefijoCodigo, $palabras, $booleana, $fueraDelIndice, $match) {
            $w->where('referencias.codigo_referencia', 'like', $prefijoCodigo);
            if ($palabras !== []) {
                $w->orWhere(function (Builder $d) use ($booleana, $fueraDelIndice, $match) {
                    if ($booleana !== '') {
                        $d->whereRaw($match, [$booleana]);
                    }
                    // Solo letras y dígitos: no hay metacaracteres de regex que escapar.
                    foreach ($fueraDelIndice as $palabra) {
                        $d->whereRaw("REGEXP_LIKE(referencias.descripcion, ?, 'i')", ['\\b'.$palabra]);
                    }
                });
            }
        });

        $query->orderByRaw('referencias.codigo_referencia LIKE ? DESC', [$prefijoCodigo]);
        if ($booleana !== '') {
            $query->orderByRaw($match.' DESC', [$booleana]);
        }

        return $query->orderBy('referencias.codigo_referencia');
    }
}
