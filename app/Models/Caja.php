<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $empresa_id
 * @property int $sucursal_id
 * @property string $nombre
 * @property string|null $codigo
 * @property string $modalidad
 * @property bool $activa
 * @property bool $es_principal
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Sucursal $sucursal
 * @property-read TurnoCaja|null $turnoActivo
 */
#[Fillable(['nombre', 'codigo', 'modalidad', 'activa', 'es_principal'])]
class Caja extends Model
{
    use BelongsToEmpresa;

    protected $table = 'cajas';

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
            'es_principal' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class)->withoutGlobalScopes();
    }

    /**
     * @return HasMany<User, $this>
     */
    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class)->withoutGlobalScopes();
    }

    /**
     * @return HasMany<TurnoCaja, $this>
     */
    public function turnos(): HasMany
    {
        return $this->hasMany(TurnoCaja::class)->withoutGlobalScopes();
    }

    /**
     * @return HasOne<TurnoCaja, $this>
     */
    public function turnoActivo(): HasOne
    {
        return $this->hasOne(TurnoCaja::class)->withoutGlobalScopes()->where('estado', 'abierto');
    }

    public function tieneTurnoAbierto(): bool
    {
        return $this->turnos()->where('estado', 'abierto')->exists();
    }
}
