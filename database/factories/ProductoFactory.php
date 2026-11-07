<?php

namespace Database\Factories;

use App\Models\Empresa;
use App\Models\Producto;
use App\Models\UnidadMedida;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Solo para pruebas automatizadas. Crea la unidad en la misma empresa del producto.
 *
 * @extends Factory<Producto>
 */
class ProductoFactory extends Factory
{
    protected $model = Producto::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'unidad_medida_id' => function (array $atributos) {
                $unidad = new UnidadMedida([
                    'nombre' => 'Unidad '.fake()->unique()->numerify('####'),
                    'abreviatura' => 'und.',
                    'admite_decimales' => false,
                    'unidad_medida_sin' => 57,
                ]);
                $unidad->empresa_id = $atributos['empresa_id'];
                $unidad->save();

                return $unidad->id;
            },
            'sku' => fake()->unique()->bothify('PRD-####'),
            'nombre' => 'Producto '.fake()->unique()->word(),
            'precio_consumidor' => '13.00',
            'precio_minorista' => '11.10',
            'precio_mayorista' => '9.80',
            'stock_minimo' => '0',
            'activo' => true,
        ];
    }

    public function enUnidad(UnidadMedida $unidad): static
    {
        return $this->state(fn () => ['empresa_id' => $unidad->empresa_id, 'unidad_medida_id' => $unidad->id]);
    }

    public function porMedida(): static
    {
        return $this->state(fn () => ['tipo_venta' => 'medida']);
    }
}
