<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Sucursal de MoziStock registrada en el padrón del SIN con su código (0 = casa matriz).
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $sucursal_id
 * @property int $codigo
 * @property string $municipio
 * @property string|null $telefono
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SiatSucursal extends Model
{
    use BelongsToEmpresa;

    protected $table = 'siat_sucursales';

    protected function casts(): array
    {
        return [
            'codigo' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return HasMany<SiatPuntoVenta, $this>
     */
    public function puntosVenta(): HasMany
    {
        return $this->hasMany(SiatPuntoVenta::class);
    }
}
