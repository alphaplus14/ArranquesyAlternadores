<?php

namespace Database\Factories;

use App\Models\Categoria;
use App\Models\CodigoUniversal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CodigoUniversal>
 */
class CodigoUniversalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'codigo' => strtoupper(fake()->unique()->bothify('IM###')),
            'categoria_id' => Categoria::factory()->conUniversal(),
            'descripcion' => fake()->sentence(3),
        ];
    }
}
