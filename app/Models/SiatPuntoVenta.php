<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Punto de venta SIN de una sucursal. El 0 se crea con la sucursal y la representa.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $siat_sucursal_id
 * @property int $codigo
 * @property string $nombre
 * @property string|null $tipo
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SiatSucursal $siatSucursal
 */
class SiatPuntoVenta extends Model
{
    use BelongsToEmpresa;

    protected $table = 'siat_puntos_venta';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'activo' => true,
    ];

    protected function casts(): array
    {
        return [
            'codigo' => 'integer',
            'activo' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<SiatSucursal, $this>
     */
    public function siatSucursal(): BelongsTo
    {
        return $this->belongsTo(SiatSucursal::class);
    }

    /**
     * @return HasMany<SiatCuis, $this>
     */
    public function cuis(): HasMany
    {
        return $this->hasMany(SiatCuis::class);
    }

    /**
     * @return HasMany<SiatCufd, $this>
     */
    public function cufd(): HasMany
    {
        return $this->hasMany(SiatCufd::class);
    }
}
