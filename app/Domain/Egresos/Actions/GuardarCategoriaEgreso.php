<?php

namespace App\Domain\Egresos\Actions;

use App\Models\CategoriaEgreso;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Categorías de egresos de la empresa. No se eliminan: se desactivan y conservan sus egresos.
 */
final class GuardarCategoriaEgreso
{
    public function crear(User $actor, string $nombre, ?string $descripcion): CategoriaEgreso
    {
        Gate::forUser($actor)->authorize('create', CategoriaEgreso::class);

        $categoria = new CategoriaEgreso;
        $categoria->empresa_id = (int) $actor->empresa_id;
        $categoria->nombre = trim($nombre);
        $categoria->descripcion = $this->descripcion($descripcion);
        $categoria->save();

        return $categoria;
    }

    public function actualizar(User $actor, CategoriaEgreso $categoria, string $nombre, ?string $descripcion, bool $activa): CategoriaEgreso
    {
        Gate::forUser($actor)->authorize('update', $categoria);

        $categoria->nombre = trim($nombre);
        $categoria->descripcion = $this->descripcion($descripcion);
        $categoria->activa = $activa;
        $categoria->save();

        return $categoria;
    }

    private function descripcion(?string $valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : mb_substr($texto, 0, 150);
    }
}
