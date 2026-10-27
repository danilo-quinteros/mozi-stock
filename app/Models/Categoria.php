<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $empresa_id
 * @property string $nombre
 * @property bool $activa
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['nombre'])]
class Categoria extends Model
{
    use BelongsToEmpresa;

    protected $table = 'categorias';

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
     * @return HasMany<Producto, $this>
     */
    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class);
    }
}
