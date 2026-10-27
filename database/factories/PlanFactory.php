<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo' => fake()->unique()->slug(),
            'titulo' => fake()->words(3, true),
            'descripcion' => fake()->sentence(),
            'precio_mensual' => '150.00',
            'siat_habilitado' => false,
            'activo' => true,
            'orden' => fake()->numberBetween(1, 10),
        ];
    }

    public function siat(): static
    {
        return $this->state(fn () => [
            'titulo' => 'Inventarios + Facturación SIAT',
            'precio_mensual' => '300.00',
            'siat_habilitado' => true,
        ]);
    }
}
