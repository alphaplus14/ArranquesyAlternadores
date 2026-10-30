<?php

namespace Database\Factories;

use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Proveedor;
use App\Models\Referencia;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Referencia>
 */
class ReferenciaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'codigo_referencia' => strtoupper(fake()->unique()->bothify('??-####')),
            'marca_id' => Marca::factory(),
            'proveedor_id' => Proveedor::factory(),
            'categoria_id' => Categoria::factory(),
            'codigo_universal_id' => null,
            'descripcion' => fake()->sentence(3),
            'precio_venta' => fake()->numberBetween(10, 900) * 1000,
        ];
    }
}
