<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Database\Factories\SucursalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $empresa_id
 * @property string $nombre
 * @property string $codigo
 * @property string|null $direccion
 * @property bool $activa
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['nombre', 'codigo', 'direccion'])]
class Sucursal extends Model
{
    use BelongsToEmpresa;

    /** @use HasFactory<SucursalFactory> */
    use HasFactory;

    protected $table = 'sucursales';

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
     * @return HasMany<User, $this>
     */
    public function usuarios(): HasMany
    {
        // La FK compuesta (empresa_id, sucursal_id) garantiza que el usuario es de la misma empresa.
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Caja, $this>
     */
    public function cajas(): HasMany
    {
        return $this->hasMany(Caja::class);
    }

    public function cajaPrincipal(): ?Caja
    {
        return Caja::withoutGlobalScopes()
            ->where('empresa_id', $this->empresa_id)
            ->where('sucursal_id', $this->id)
            ->where('es_principal', true)
            ->first();
    }
}
