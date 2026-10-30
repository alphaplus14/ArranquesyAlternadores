<?php

namespace Database\Factories;

use App\Models\Categoria;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Categoria>
 */
class CategoriaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->words(2, true),
            'admite_codigo_universal' => false,
        ];
    }

    /** RF-01 */
    public function conUniversal(): static
    {
        return $this->state(fn () => ['admite_codigo_universal' => true]);
    }
}
