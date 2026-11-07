<?php

namespace App\Models;

use App\Enums\Capacidad;
use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Capacidad de una empresa, encendida o apagada. Solo la escriben AprovisionarEmpresa (alta) y
 * CambiarFuncionesEmpresa (cambios); la lectura de la petición pasa por CapacidadesEmpresa.
 *
 * @property int $id
 * @property int $empresa_id
 * @property Capacidad $capacidad
 * @property bool $activa
 * @property int|null $cambiada_por
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EmpresaCapacidad extends Model
{
    use BelongsToEmpresa;

    protected $table = 'empresa_capacidades';

    protected function casts(): array
    {
        return [
            'capacidad' => Capacidad::class,
            'activa' => 'boolean',
        ];
    }
}
