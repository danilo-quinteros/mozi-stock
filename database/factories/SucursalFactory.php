<?php

namespace Database\Factories;

use App\Models\Empresa;
use App\Models\Sucursal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Solo para pruebas automatizadas.
 *
 * @extends Factory<Sucursal>
 */
class SucursalFactory extends Factory
{
    protected $model = Sucursal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'nombre' => 'Sucursal '.fake()->unique()->city(),
            'codigo' => fake()->unique()->bothify('SUC-###'),
            'direccion' => fake()->streetAddress(),
            'activa' => true,
        ];
    }

    public function inactiva(): static
    {
        return $this->state(fn () => ['activa' => false]);
    }
}
