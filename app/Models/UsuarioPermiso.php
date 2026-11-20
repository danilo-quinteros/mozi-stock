<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Permiso extra de un usuario (ESPECIFICACION_PERMISOS.md § 3): solo suma a los de su rol. Solo
 * lectura: se escribe con GuardarUsuarioEmpresa.
 *
 * @property int $user_id
 * @property string $permiso
 * @property int|null $otorgado_por
 * @property Carbon|null $created_at
 */
class UsuarioPermiso extends Model
{
    protected $table = 'usuario_permisos';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'user_id';

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
