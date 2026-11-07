<?php

namespace Database\Factories;

use App\Models\Empresa;
use App\Models\Sucursal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

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

    public function configure(): static
    {
        return $this->afterCreating(function (Sucursal $sucursal): void {
            DB::table('cajas')->insertOrIgnore([
                'empresa_id' => $sucursal->empresa_id,
                'sucursal_id' => $sucursal->id,
                'nombre' => 'Caja principal',
                'codigo' => 'CP-'.$sucursal->codigo,
                'modalidad' => 'compartida',
                'activa' => true,
                'es_principal' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function inactiva(): static
    {
        return $this->state(fn () => ['activa' => false]);
    }
}
