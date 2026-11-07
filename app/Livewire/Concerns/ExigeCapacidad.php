<?php

namespace App\Livewire\Concerns;

use App\Enums\Capacidad;
use Illuminate\Support\Facades\Gate;

/**
 * Componentes Livewire de un módulo por rubro: llamar en mount() y en cada acción que modifique datos.
 * Con la capacidad apagada responde 404, aunque la acción se invoque manipulando la petición.
 */
trait ExigeCapacidad
{
    protected function exigirCapacidad(Capacidad $capacidad): void
    {
        abort_unless(Gate::allows('capacidad', $capacidad), 404);
    }
}
