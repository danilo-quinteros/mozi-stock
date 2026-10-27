<?php

namespace Database\Factories;

use App\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Solo para pruebas automatizadas; ningún seeder crea empresas de demostración.
 *
 * @extends Factory<Empresa>
 */
class EmpresaFactory extends Factory
{
    protected $model = Empresa::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->company(),
            'email' => fake()->unique()->safeEmail(),
            'activa' => true,
            'siat_habilitado' => false,
        ];
    }

    public function inactiva(): static
    {
        return $this->state(fn () => ['activa' => false]);
    }
}
