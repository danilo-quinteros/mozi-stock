<?php

namespace App\Livewire\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

/**
 * Listas con filtro «sucursal»: si el administrador fijó una sucursal en el menú lateral,
 * la pantalla arranca filtrada por ella y el selector queda bloqueado. Es una comodidad de
 * navegación, no un permiso: el acceso real lo siguen decidiendo las policies.
 */
trait UsaSucursalActiva
{
    #[Locked]
    public bool $sucursalFija = false;

    protected function aplicarSucursalActiva(): void
    {
        /** @var User|null $actor */
        $actor = Auth::user();
        $id = $actor?->esAdministrador() ? $actor->sucursalActivaId() : null;

        if ($id !== null) {
            $this->sucursal = (string) $id;
            $this->sucursalFija = true;
        }
    }
}
