<?php

namespace App\Models;

use App\Enums\Rol;
use App\Models\Concerns\Auditable;
use App\Rules\NombreUsuario;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'usuario',
        'password',
        'rol',
        'activo',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'rol' => Rol::class,
            'activo' => 'boolean',
        ];
    }

    /** Nombre de usuario con que se inicia sesión: siempre recortado y en minúsculas. */
    protected function usuario(): Attribute
    {
        return Attribute::make(set: fn ($valor) => NombreUsuario::normalizar($valor));
    }

    /** El admin es superusuario: pasa en todos los módulos. */
    public function tieneRol(Rol ...$roles): bool
    {
        return $this->rol === Rol::Admin || in_array($this->rol, $roles, true);
    }
}
