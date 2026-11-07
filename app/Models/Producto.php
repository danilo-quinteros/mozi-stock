<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Support\Decimal;
use Database\Factories\ProductoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ficha única compartida por todas las sucursales. Nunca guarda stock global:
 * las existencias viven en stock_niveles por sucursal.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int|null $categoria_id
 * @property int $unidad_medida_id
 * @property string $tipo_venta
 * @property string|null $sku
 * @property string|null $codigo_barras
 * @property string $nombre
 * @property string|null $contenido
 * @property string|null $descripcion
 * @property string $precio_consumidor
 * @property string|null $precio_minorista
 * @property string|null $precio_mayorista
 * @property string $stock_minimo
 * @property string|null $imagen_path
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $nombre_base
 * @property-read string $nombre_completo
 * @property-read string|null $stock Solo en ListadoProductos: stock de la sucursal consultada.
 * @property-read string|null $minimo Solo en ListadoProductos: mínimo efectivo en esa sucursal.
 * @property-read Categoria|null $categoria
 * @property-read UnidadMedida $unidad
 */
#[Fillable(['sku', 'codigo_barras', 'nombre', 'contenido', 'tipo_venta', 'descripcion'])]
class Producto extends Model
{
    use BelongsToEmpresa;

    /** @use HasFactory<ProductoFactory> */
    use HasFactory;

    protected $table = 'productos';

    /**
     * @var array<int, string>
     */
    private static array $abreviaturasCache = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'stock_minimo' => '0.000',
        'tipo_venta' => 'unidad',
        'activo' => true,
    ];

    protected function casts(): array
    {
        return [
            'precio_consumidor' => 'decimal:2',
            'precio_minorista' => 'decimal:2',
            'precio_mayorista' => 'decimal:2',
            'stock_minimo' => 'decimal:3',
            'contenido' => 'decimal:3',
            'tipo_venta' => 'string',
            'activo' => 'boolean',
        ];
    }

    /**
     * Nombre comercial completo automático ("Desengrasante 350 g") cuando tiene contenido.
     *
     * @return Attribute<string, string>
     */
    protected function nombre(): Attribute
    {
        return Attribute::make(
            get: function (?string $value, array $attributes) {
                $base = (string) ($attributes['nombre'] ?? $value ?? '');
                $contenido = $attributes['contenido'] ?? null;

                if ($contenido === null || $contenido === '') {
                    return $base;
                }

                $unidadId = isset($attributes['unidad_medida_id']) ? (int) $attributes['unidad_medida_id'] : 0;
                $abrev = $this->relationLoaded('unidad')
                    ? $this->unidad->abreviatura
                    : self::abreviaturaUnidad($unidadId);

                return self::formatoNombreCompleto($base, $contenido, $abrev);
            },
            set: fn ($value) => trim((string) $value),
        );
    }

    /**
     * Nombre base sin contenido ni unidad (el campo editable en el formulario).
     *
     * @return Attribute<string, never>
     */
    protected function nombreBase(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value, array $attributes) => (string) ($attributes['nombre'] ?? ''),
        );
    }

    /**
     * Alias explícito del nombre comercial completo.
     *
     * @return Attribute<string, never>
     */
    protected function nombreCompleto(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->nombre,
        );
    }

    public static function abreviaturaUnidad(int $unidadId): string
    {
        if ($unidadId <= 0) {
            return '';
        }

        if (! array_key_exists($unidadId, self::$abreviaturasCache)) {
            self::$abreviaturasCache[$unidadId] = (string) (DB::table('unidades_medida')
                ->where('id', $unidadId)
                ->value('abreviatura') ?? '');
        }

        return self::$abreviaturasCache[$unidadId];
    }

    public static function limpiarCacheAbreviaturas(): void
    {
        self::$abreviaturasCache = [];
    }

    public static function formatoNombreCompleto(string $nombre, string|int|float|null $contenido = null, ?string $unidadAbreviatura = null): string
    {
        $nombreLimpio = trim($nombre);

        if ($contenido === null || $contenido === '') {
            return $nombreLimpio;
        }

        $cant = Decimal::simple((string) $contenido);
        if ($cant === '' || Decimal::comparar($cant, '0') <= 0) {
            return $nombreLimpio;
        }

        $abrev = trim((string) $unidadAbreviatura);

        return trim($nombreLimpio.' '.$cant.($abrev !== '' ? ' '.$abrev : ''));
    }

    public function esPorUnidad(): bool
    {
        return ($this->tipo_venta ?? 'unidad') === 'unidad';
    }

    public function esPorMedida(): bool
    {
        return ($this->tipo_venta ?? 'unidad') === 'medida';
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

    /**
     * @return HasOne<ProductoVinculoExterno, $this>
     */
    public function vinculoExterno(): HasOne
    {
        return $this->hasOne(ProductoVinculoExterno::class, 'producto_id');
    }
}
