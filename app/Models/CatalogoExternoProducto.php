<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Producto en el catálogo central de MoziStock descargado desde un proveedor externo.
 * No pertenece a ninguna empresa en particular (centralizado globalmente).
 * NUNCA almacena costos ni precios.
 *
 * @property int $id
 * @property int $proveedor_externo_id
 * @property string $externo_id
 * @property string|null $codigo
 * @property string $nombre_original
 * @property string $nombre_limpio
 * @property string|null $contenido
 * @property string $unidad_sugerida
 * @property string $tipo_venta
 * @property string|null $descripcion
 * @property string|null $linea_nombre
 * @property string|null $foto_url
 * @property string|null $foto_cache_path
 * @property bool $activo_origen
 * @property bool $disponible
 * @property string $hash
 * @property CarbonImmutable|null $origen_updated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ProveedorExterno $proveedor
 */
#[Fillable([
    'proveedor_externo_id', 'externo_id', 'codigo', 'nombre_original', 'nombre_limpio',
    'contenido', 'unidad_sugerida', 'unidad_siat_sugerida', 'tipo_venta', 'descripcion', 'linea_nombre',
    'foto_url', 'foto_cache_path', 'activo_origen', 'disponible', 'hash', 'origen_updated_at',
])]
class CatalogoExternoProducto extends Model
{
    protected $table = 'catalogo_externo_productos';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'unidad_sugerida' => 'und.',
        'tipo_venta' => 'unidad',
        'activo_origen' => true,
        'disponible' => true,
    ];

    protected function casts(): array
    {
        return [
            'contenido' => 'decimal:3',
            'activo_origen' => 'boolean',
            'disponible' => 'boolean',
            'origen_updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ProveedorExterno, $this>
     */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(ProveedorExterno::class, 'proveedor_externo_id');
    }
}
