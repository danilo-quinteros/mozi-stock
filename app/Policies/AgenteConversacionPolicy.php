<?php

namespace App\Policies;

use App\Models\AgenteConversacion;
use App\Models\User;

/**
 * Las conversaciones con Agente Mozi son privadas de su autor, incluso dentro de la misma empresa:
 * pueden contener preguntas y resultados que su rol puede ver y otro usuario no.
 */
class AgenteConversacionPolicy
{
    public function view(User $user, AgenteConversacion $conversacion): bool
    {
        return $user->can('usar-agente-mozi')
            && $conversacion->empresa_id === $user->empresa_id
            && $conversacion->usuario_id === $user->id;
    }
}
