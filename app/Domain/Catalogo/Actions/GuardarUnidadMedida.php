<?php

namespace App\Domain\Catalogo\Actions;

use App\Models\UnidadMedida;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Unidades y medidas propias de la empresa. No se eliminan: se desactivan.
 */
final class GuardarUnidadMedida
{
    /**
     * @param  array{nombre: string, abreviatura: string, admite_decimales: bool, unidad_medida_sin?: int|string|null}  $datos
     */
    public function crear(User $actor, array $datos): UnidadMedida
    {
        Gate::forUser($actor)->authorize('create', UnidadMedida::class);

        $unidad = new UnidadMedida;
        $unidad->empresa_id = (int) $actor->empresa_id;
        $this->aplicar($unidad, $datos);
        $unidad->save();

        return $unidad;
    }

    /**
     * @param  array{nombre: string, abreviatura: string, admite_decimales: bool, activa: bool, unidad_medida_sin?: int|string|null}  $datos
     */
    public function actualizar(User $actor, UnidadMedida $unidad, array $datos): UnidadMedida
    {
        Gate::forUser($actor)->authorize('update', $unidad);

        $this->aplicar($unidad, $datos);
        $unidad->activa = $datos['activa'];
        $unidad->save();

        return $unidad;
    }

    /**
     * @param  array{nombre: string, abreviatura: string, admite_decimales: bool, unidad_medida_sin?: int|string|null}  $datos
     */
    private function aplicar(UnidadMedida $unidad, array $datos): void
    {
        $unidad->nombre = trim($datos['nombre']);
        $unidad->abreviatura = trim($datos['abreviatura']);
        $unidad->admite_decimales = $datos['admite_decimales'];
        if (array_key_exists('unidad_medida_sin', $datos)) {
            $unidad->unidad_medida_sin = filled($datos['unidad_medida_sin']) ? (int) $datos['unidad_medida_sin'] : null;
        }
    }
}
