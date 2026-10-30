<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** RF-03: consecutivos del local (código de barras). */
class Secuencia extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'secuencias';

    protected $primaryKey = 'nombre';

    protected $keyType = 'string';

    protected $fillable = ['nombre', 'valor'];
}
