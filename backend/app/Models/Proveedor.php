<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proveedor extends Model
{
    use Auditable, HasFactory;

    protected $table = 'proveedores';

    protected $fillable = ['nombre', 'nit', 'plazo_dias'];

    protected function casts(): array
    {
        return ['plazo_dias' => 'integer'];
    }

    public function referencias(): HasMany
    {
        return $this->hasMany(Referencia::class);
    }
}
