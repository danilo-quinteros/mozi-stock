<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Proveedor externo del cual MoziStock puede importar catálogos de productos (ej. Cyclone).
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property string $driver
 * @property string|null $base_url
 * @property string|null $token
 * @property bool $activo
 * @property CarbonImmutable|null $ultima_descarga_en
 * @property string|null $ultimo_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['codigo', 'nombre', 'driver', 'base_url', 'token', 'activo', 'ultima_descarga_en', 'ultimo_error'])]
class ProveedorExterno extends Model
{
    protected $table = 'proveedores_externos';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'activo' => true,
        'driver' => 'cyclone',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'activo' => 'boolean',
            'ultima_descarga_en' => 'datetime',
        ];
    }

    /**
     * @return HasMany<CatalogoExternoProducto, $this>
     */
    public function productosCatalogo(): HasMany
    {
        return $this->hasMany(CatalogoExternoProducto::class, 'proveedor_externo_id');
    }

    /**
     * @return HasMany<EmpresaProveedorExterno, $this>
     */
    public function empresasVinculadas(): HasMany
    {
        return $this->hasMany(EmpresaProveedorExterno::class, 'proveedor_externo_id');
    }
}
