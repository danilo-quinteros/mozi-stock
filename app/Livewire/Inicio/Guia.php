<?php

namespace App\Livewire\Inicio;

use App\Domain\Tenancy\GuiaConfiguracion;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Guía de configuración inicial del Inicio (onboarding.html). Los popups muestran los mismos
 * componentes de Mi empresa, Sucursales y Facturación en modo embebido: guardan con sus propias
 * validaciones y permisos. Al cerrar un popup la guía se vuelve a calcular con los datos guardados.
 */
class Guia extends Component
{
    private const POPUPS = ['empresa', 'sucursal'];

    /** Solo existen si la empresa tiene la facturación SIAT habilitada. */
    private const POPUPS_SIAT = ['token', 'datos-fiscales', 'sucursal-siat'];

    #[Locked]
    public ?string $popup = null;

    public function mount(): void
    {
        abort_unless($this->actor()->esAdministrador(), 403);
    }

    public function abrir(string $popup): void
    {
        $this->popup = in_array($popup, [...self::POPUPS, ...self::POPUPS_SIAT], true) ? $popup : null;
    }

    public function cerrar(): void
    {
        $this->popup = null;
    }

    public function render(): View
    {
        $guia = GuiaConfiguracion::para($this->actor());

        if ($guia === null || ($guia['siat'] === null && in_array($this->popup, self::POPUPS_SIAT, true))) {
            $this->popup = null;
        }

        return view('livewire.inicio.guia', ['guia' => $guia, 'empresaId' => (int) $this->actor()->empresa_id]);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
