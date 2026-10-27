<?php

namespace App\Domain\Catalogo\Actions;

use App\Models\Categoria;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Categorías de productos de la empresa. No se eliminan: se desactivan.
 */
final class GuardarCategoria
{
    public function crear(User $actor, string $nombre): Categoria
    {
        Gate::forUser($actor)->authorize('create', Categoria::class);

        $categoria = new Categoria;
        $categoria->empresa_id = (int) $actor->empresa_id;
        $categoria->nombre = trim($nombre);
        $categoria->save();

        return $categoria;
    }

    public function actualizar(User $actor, Categoria $categoria, string $nombre, bool $activa): Categoria
    {
        Gate::forUser($actor)->authorize('update', $categoria);

        $categoria->nombre = trim($nombre);
        $categoria->activa = $activa;
        $categoria->save();

        return $categoria;
    }
}
