<?php

namespace App\Models;

use App\Enums\Permiso;
use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Rol de una empresa: conjunto de permisos con nombre (ESPECIFICACION_PERMISOS.md § 3). El Administrador
 * no es un rol: es un tipo de cuenta con todos los permisos. Los permisos se guardan solo con
 * App\Domain\Identidad\Actions\GuardarRol.
 *
 * @property int $id
 * @property int $empresa_id
 * @property string $nombre
 * @property string|null $descripcion
 * @property string|null $plantilla vendedor | encargado: rol creado por defecto (solo informativo)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $usuarios_count
 * @property-read int|null $permisos_count
 */
#[Fillable(['nombre', 'descripcion'])]
class Rol extends Model
{
    use BelongsToEmpresa;

    public const PLANTILLA_VENDEDOR = 'vendedor';

    public const PLANTILLA_ENCARGADO = 'encargado';

    protected $table = 'roles';

    /**
     * @return HasMany<RolPermiso, $this>
     */
    public function permisos(): HasMany
    {
        return $this->hasMany(RolPermiso::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Permisos guardados del rol (con sus requeridos), en el orden del catálogo.
     *
     * @return list<Permiso>
     */
    public function clavesPermisos(): array
    {
        $claves = $this->relationLoaded('permisos')
            ? $this->permisos->pluck('permiso')
            : $this->permisos()->pluck('permiso');

        return Permiso::conRequeridos(Permiso::desdeClaves($claves));
    }
}
