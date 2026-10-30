<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Marca extends Model
{
    use Auditable, HasFactory;

    protected $table = 'marcas';

    protected $fillable = ['nombre'];

    public function referencias(): HasMany
    {
        return $this->hasMany(Referencia::class);
    }
}
