<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Proveedor de una empresa: identificación básica y contacto. No se elimina; se desactiva.
 *
 * @property int $id
 * @property int $empresa_id
 * @property string $nombre
 * @property string|null $documento
 * @property string|null $telefono
 * @property string|null $correo
 * @property string|null $ciudad
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['nombre', 'documento', 'telefono', 'correo', 'ciudad'])]
class Proveedor extends Model
{
    use BelongsToEmpresa;

    protected $table = 'proveedores';

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
     * @return HasMany<Compra, $this>
     */
    public function compras(): HasMany
    {
        return $this->hasMany(Compra::class);
    }
}
