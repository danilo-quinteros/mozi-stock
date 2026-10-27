<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Database\Factories\ProductoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Ficha única compartida por todas las sucursales. Nunca guarda stock global:
 * las existencias viven en stock_niveles por sucursal.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int|null $categoria_id
 * @property int $unidad_medida_id
 * @property string $sku
 * @property string|null $codigo_barras
 * @property string $nombre
 * @property string|null $descripcion
 * @property string $precio_consumidor
 * @property string|null $precio_minorista
 * @property string|null $precio_mayorista
 * @property string $stock_minimo
 * @property string|null $imagen_path
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string|null $stock Solo en ListadoProductos: stock de la sucursal consultada.
 * @property-read string|null $minimo Solo en ListadoProductos: mínimo efectivo en esa sucursal.
 * @property-read Categoria|null $categoria
 * @property-read UnidadMedida $unidad
 */
#[Fillable(['sku', 'codigo_barras', 'nombre', 'descripcion'])]
class Producto extends Model
{
    use BelongsToEmpresa;

    /** @use HasFactory<ProductoFactory> */
    use HasFactory;

    protected $table = 'productos';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'stock_minimo' => '0.000',
        'activo' => true,
    ];

    protected function casts(): array
    {
        return [
            'precio_consumidor' => 'decimal:2',
            'precio_minorista' => 'decimal:2',
            'precio_mayorista' => 'decimal:2',
            'stock_minimo' => 'decimal:3',
            'activo' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Categoria, $this>
     */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    /**
     * @return BelongsTo<UnidadMedida, $this>
     */
    public function unidad(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_medida_id');
    }

    /**
     * @return HasMany<StockNivel, $this>
     */
    public function stock(): HasMany
    {
        return $this->hasMany(StockNivel::class);
    }
}
