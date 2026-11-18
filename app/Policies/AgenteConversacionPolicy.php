<?php

namespace App\Policies;

use App\Models\AgenteConversacion;
use App\Models\User;

/**
 * Las conversaciones con Agente Mozi son privadas de su autor, incluso dentro de la misma empresa:
 * pueden contener preguntas y resultados que su rol puede ver y otro usuario no. Una charla eliminada
 * ya no se ve ni se continúa; solo su autor puede eliminarla.
 */
class AgenteConversacionPolicy
{
    public function view(User $user, AgenteConversacion $conversacion): bool
    {
        return $this->esAutor($user, $conversacion) && ! $conversacion->eliminada();
    }

    /** Eliminar dos veces la misma charla (doble clic, otra pestaña) no es un error: EliminarConversacion no hace nada. */
    public function delete(User $user, AgenteConversacion $conversacion): bool
    {
        return $this->esAutor($user, $conversacion);
    }

    private function esAutor(User $user, AgenteConversacion $conversacion): bool
    {
        return $user->can('usar-agente-mozi')
            && $conversacion->empresa_id === $user->empresa_id
            && $conversacion->usuario_id === $user->id;
    }
}
