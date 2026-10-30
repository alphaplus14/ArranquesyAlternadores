<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Categoria extends Model
{
    use Auditable, HasFactory;

    protected $table = 'categorias';

    protected $fillable = ['nombre', 'admite_codigo_universal'];

    protected function casts(): array
    {
        return ['admite_codigo_universal' => 'boolean'];
    }

    public function referencias(): HasMany
    {
        return $this->hasMany(Referencia::class);
    }
}
