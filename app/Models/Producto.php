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
 * @property string|null $marca opcional; en farmacias «Marca / laboratorio». Va en el ticket y la factura: «Paracetamol 500 mg (Bayer)»
 * @property string|null $descripcion
 * @property string $precio_consumidor
 * @property string|null $precio_minorista
 * @property string|null $precio_mayorista
 * @property string $stock_minimo
 * @property string|null $imagen_path
 * @property bool $activo
 * @property bool $controla_stock false: se vende sin mover stock (ESPECIFICACION_PRODUCTOS_SIN_STOCK.md)
 * @property bool $controla_vencimiento stock por lote (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md); exige controla_stock
 * @property int|null $modelo_id variante de un modelo (ESPECIFICACION_TALLAS_Y_COLORES.md § 6.3); NULL = producto suelto
 * @property int|null $talla_id
 * @property int|null $color_id
 * @property bool $precio_propio false: usa el precio del modelo; true: el cambio de precio del modelo no la toca
 * @property-read string|null $clave_variante columna generada «modelo-talla-color», única por empresa
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $nombre_base
 * @property-read string $nombre_completo
 * @property-read string|null $stock Solo en ListadoProductos: stock de la sucursal consultada.
 * @property-read string|null $minimo Solo en ListadoProductos: mínimo efectivo en esa sucursal.
 * @property-read Categoria|null $categoria
 * @property-read UnidadMedida $unidad
 * @property-read Modelo|null $modelo
 * @property-read Talla|null $talla
 * @property-read Color|null $color
 */
#[Fillable(['sku', 'codigo_barras', 'nombre', 'marca', 'contenido', 'tipo_venta', 'descripcion'])]
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
        'controla_stock' => true,
        'controla_vencimiento' => false,
        'precio_propio' => false,
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
            'controla_stock' => 'boolean',
            'controla_vencimiento' => 'boolean',
            'precio_propio' => 'boolean',
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

    public const MAX_MARCA = 60;

    /**
     * Condición de búsqueda de productos por texto, la misma en buscadores, POS y listado: nombre, marca, SKU, código de
     * barras exacto, contenido o el nombre completo con la marca («Paracetamol 500 mg Bayer»). $p es el alias de la
     * tabla productos y $um el de unidades_medida, que la consulta debe tener unida.
     *
     * @template TBuilder of \Illuminate\Database\Eloquent\Builder<*>|\Illuminate\Database\Query\Builder
     *
     * @param  TBuilder  $consulta
     * @return TBuilder
     */
    public static function filtrarPorTexto($consulta, string $termino, string $p = 'productos', string $um = 'um')
    {
        $patron = '%'.addcslashes($termino, '\\%_').'%';

        return $consulta->where(fn ($q) => $q->where("{$p}.nombre", 'like', $patron)
            ->orWhere("{$p}.marca", 'like', $patron)
            ->orWhere("{$p}.sku", 'like', $patron)
            ->orWhere("{$p}.codigo_barras", $termino)
            ->orWhere("{$p}.contenido", 'like', $patron)
            ->orWhereRaw("CONCAT_WS(' ', {$p}.nombre, {$p}.contenido, {$um}.abreviatura) LIKE ?", [$patron])
            ->orWhereRaw("CONCAT_WS(' ', {$p}.nombre, {$p}.marca) LIKE ?", [$patron]));
    }

    /** Marca escrita por el usuario: sin espacios sobrantes; vacía = sin marca. */
    public static function normalizarMarca(?string $marca): ?string
    {
        $limpia = trim((string) preg_replace('/\s+/u', ' ', (string) $marca));

        return $limpia === '' ? null : $limpia;
    }

    /**
     * Descripción para el ticket y la factura: «Paracetamol 500 mg (Bayer)». Así dos marcas del mismo producto no se
     * ven iguales con precios distintos. Se recorta a $max caracteres (venta_items.descripcion admite 150).
     */
    public static function descripcionConMarca(string $nombre, ?string $marca, int $max = 150): string
    {
        $marca = self::normalizarMarca($marca);
        $texto = $marca === null ? trim($nombre) : trim($nombre).' ('.$marca.')';

        return mb_strlen($texto) > $max ? rtrim(mb_substr($texto, 0, $max)) : $texto;
    }

    /**
     * Cómo se vende, deducido sin preguntarlo: con contenido es un envase y se vende por unidades («Aceite 1 L»); sin
     * contenido, una unidad que admite decimales (kg, L, m) se vende por peso o medida y las demás por unidades. Es la
     * regla de la ficha del producto y de la importación. `tipo_venta = unidad` obliga a cantidades enteras.
     */
    public static function tipoVentaPara(?string $contenido, bool $admiteDecimales): string
    {
        return ($contenido === null || trim($contenido) === '') && $admiteDecimales ? 'medida' : 'unidad';
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

    /** Combinación de talla y color de un modelo (tallas y colores § 2). */
    public function esVariante(): bool
    {
        return $this->modelo_id !== null;
    }

    /**
     * Si hay foto que mostrar (tallas y colores § 4.5): la propia o, en una variante sin foto, la del modelo. Para no
     * consultar fila por fila, la del modelo solo cuenta si la relación `modelo` ya está cargada (las pantallas la
     * cargan solo con la capacidad activa). La sirve ProductoImagenController, que aplica el mismo respaldo.
     */
    public function tieneFoto(): bool
    {
        if ($this->imagen_path !== null) {
            return true;
        }

        return $this->modelo_id !== null && $this->relationLoaded('modelo') && $this->modelo?->imagen_path !== null;
    }

    /**
     * @return BelongsTo<Modelo, $this>
     */
    public function modelo(): BelongsTo
    {
        return $this->belongsTo(Modelo::class);
    }

    /**
     * @return BelongsTo<Talla, $this>
     */
    public function talla(): BelongsTo
    {
        return $this->belongsTo(Talla::class);
    }

    /**
     * @return BelongsTo<Color, $this>
     */
    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class);
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
