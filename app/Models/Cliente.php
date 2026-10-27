<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Cliente de una empresa. Una misma persona puede existir en varias empresas como
 * registros independientes.
 *
 * @property int $id
 * @property int $empresa_id
 * @property string $nombre
 * @property string|null $documento
 * @property string|null $telefono
 * @property string|null $correo
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['nombre', 'documento', 'telefono', 'correo'])]
class Cliente extends Model
{
    use BelongsToEmpresa;

    protected $table = 'clientes';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'activo' => true,
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Venta, $this>
     */
    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class);
    }
}
