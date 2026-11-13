<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Lista ordenada de tallas de la empresa (Letras, Calzado, Pantalón…). Un modelo usa un solo grupo
 * (ESPECIFICACION_TALLAS_Y_COLORES.md § 3.1). Se escribe solo con GuardarGrupoTalla.
 *
 * @property int $id
 * @property int $empresa_id
 * @property string $nombre
 * @property int $orden
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Talla> $tallas
 */
#[Fillable(['nombre'])]
class GrupoTalla extends Model
{
    use BelongsToEmpresa;

    protected $table = 'grupos_talla';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'orden' => 0,
        'activo' => true,
    ];

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'activo' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Talla, $this>
     */
    public function tallas(): HasMany
    {
        return $this->hasMany(Talla::class)->orderBy('orden')->orderBy('id');
    }
}
