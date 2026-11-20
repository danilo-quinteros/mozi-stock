<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Permiso de un rol (clave de App\Enums\Permiso). Solo lectura: se escribe con GuardarRol.
 *
 * @property int $rol_id
 * @property string $permiso
 */
class RolPermiso extends Model
{
    protected $table = 'rol_permisos';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'rol_id';
}
