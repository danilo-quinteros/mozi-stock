<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Modelo de ropa o calzado (ESPECIFICACION_TALLAS_Y_COLORES.md § 4): agrupa sus combinaciones de talla y color.
 * Cada combinación (variante) es un producto normal con modelo_id; el modelo no tiene stock propio. Se escribe solo
 * con GuardarModelo, que mantiene en las variantes el nombre, la categoría, la unidad y el precio del modelo.
 *
 * @property int $id
 * @property int $empresa_id
 * @property string $nombre
 * @property int|null $categoria_id
 * @property int $unidad_medida_id
 * @property string|null $sku_base
 * @property string|null $descripcion
 * @property string $precio_consumidor
 * @property string|null $precio_minorista
 * @property string|null $precio_mayorista
 * @property string $stock_minimo
 * @property int|null $grupo_talla_id
 * @property string|null $imagen_path
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Categoria|null $categoria
 * @property-read UnidadMedida $unidad
 * @property-read GrupoTalla|null $grupoTalla
 * @property-read Collection<int, Producto> $variantes
 */
class Modelo extends Model
{
    use BelongsToEmpresa;

    protected $table = 'modelos';

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
     * @return BelongsTo<GrupoTalla, $this>
     */
    public function grupoTalla(): BelongsTo
    {
        return $this->belongsTo(GrupoTalla::class);
    }

    /**
     * @return HasMany<Producto, $this>
     */
    public function variantes(): HasMany
    {
        return $this->hasMany(Producto::class);
    }
}
