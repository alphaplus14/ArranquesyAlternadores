<?php

namespace Database\Seeders;

use App\Enums\Rol;
use App\Models\Categoria;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Contraseña SOLO de desarrollo. Cámbiela antes de usar el sistema en el local. */
    public const CLAVE_DESARROLLO = 'cambiar123';

    public function run(): void
    {
        foreach (Rol::cases() as $rol) {
            User::firstOrCreate(
                ['usuario' => $rol->value],
                ['name' => ucfirst($rol->value), 'password' => self::CLAVE_DESARROLLO, 'rol' => $rol, 'activo' => true],
            );
        }

        // Sección 1 del documento. RF-01 / S-02: reguladores, portadiodos, poleas y automáticos admiten universal.
        $categorias = [
            'Arranques' => false,
            'Alternadores' => false,
            'Reguladores' => true,
            'Portadiodos' => true,
            'Poleas' => true,
            'Automáticos / solenoides' => true,
            'Válvulas de inyección' => false,
            'Sensores' => false,
        ];

        foreach ($categorias as $nombre => $admite) {
            Categoria::firstOrCreate(['nombre' => $nombre], ['admite_codigo_universal' => $admite]);
        }

        $this->call(DemoSeeder::class);
    }
}
