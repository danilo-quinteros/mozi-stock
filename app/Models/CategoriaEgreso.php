<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Categoría de gastos operativos de la empresa. No se elimina; se desactiva.
 *
 * @property int $id
 * @property int $empresa_id
 * @property string $nombre
 * @property string|null $descripcion
 * @property bool $activa
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['nombre', 'descripcion'])]
class CategoriaEgreso extends Model
{
    use BelongsToEmpresa;

    /**
     * Categorías con las que empieza cada empresa.
     */
    public const BASE = [
        ['nombre' => 'Sueldos', 'descripcion' => 'Personal'],
        ['nombre' => 'Alquiler', 'descripcion' => 'Locales'],
        ['nombre' => 'Servicios', 'descripcion' => 'Luz, internet, agua'],
        ['nombre' => 'Transporte', 'descripcion' => 'Logística'],
        ['nombre' => 'Otros', 'descripcion' => 'Varios'],
    ];

    protected $table = 'categorias_egreso';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'activa' => true,
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Egreso, $this>
     */
    public function egresos(): HasMany
    {
        return $this->hasMany(Egreso::class);
    }
}
