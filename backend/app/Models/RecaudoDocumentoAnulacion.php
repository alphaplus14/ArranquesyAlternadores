<?php

namespace App\Models;

use App\Models\Concerns\Inmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** S-76: anulación de un recaudo de documento, con motivo, usuario y fecha. Inmutable. */
class RecaudoDocumentoAnulacion extends Model
{
    use Inmutable;

    public const UPDATED_AT = null;

    protected $table = 'recaudo_documento_anulaciones';

    protected $fillable = ['recaudo_id', 'motivo', 'usuario_id'];

    public function recaudo(): BelongsTo
    {
        return $this->belongsTo(RecaudoDocumento::class, 'recaudo_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
