<?php

namespace App\Livewire\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

/**
 * Listas con filtro «sucursal»: si quien trabaja en todas las sucursales fijó una en el menú
 * lateral, la pantalla arranca filtrada por ella y el selector queda bloqueado. Quien trabaja
 * solo en su sucursal la tiene siempre fija (ESPECIFICACION_PERMISOS.md § 6.2): ese caso sí es
 * un límite de acceso y el servidor ignora cualquier cambio del filtro.
 */
trait UsaSucursalActiva
{
    #[Locked]
    public bool $sucursalFija = false;

    protected function aplicarSucursalActiva(): void
    {
        /** @var User|null $actor */
        $actor = Auth::user();
        // Quien no trabaja en todas las sucursales recibe siempre la suya (ESPECIFICACION_PERMISOS.md § 6.2).
        $id = $actor?->perteneceAEmpresa() ? $actor->sucursalActivaId() : null;

        if ($id !== null) {
            $this->sucursal = (string) $id;
            $this->sucursalFija = true;
        }
    }

    /**
     * Sucursal del filtro, acotada otra vez al alcance del usuario en el servidor (null = todas).
     */
    protected function sucursalFiltro(): ?int
    {
        $id = ctype_digit($this->sucursal) ? (int) $this->sucursal : null;
        /** @var User|null $actor */
        $actor = Auth::user();

        return $actor === null ? $id : $actor->acotarSucursal($id);
    }

    /**
     * Con la sucursal fija nadie la cambia desde el navegador, tampoco manipulando la petición de Livewire:
     * el servidor ignora el valor recibido y vuelve a la sucursal fija (§ 6.2).
     */
    public function updatedUsaSucursalActiva(string $propiedad): void
    {
        if ($propiedad !== 'sucursal' || ! $this->sucursalFija) {
            return;
        }

        /** @var User|null $actor */
        $actor = Auth::user();
        $id = $actor?->sucursalActivaId();

        if ($id !== null) {
            $this->sucursal = (string) $id;
        }
    }
}
