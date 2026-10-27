<?php

namespace App\Livewire\Facturacion;

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Facturación electrónica SIAT de una empresa. El administrador la abre desde «Mi cuenta» (siempre
 * su propia empresa, sin ID en la URL); el superadmin, desde el panel global con la empresa en la ruta.
 */
#[Title('Facturación')]
class Panel extends Component
{
    public const SECCIONES = ['datos', 'puntos', 'catalogos', 'facturas'];

    #[Locked]
    public int $empresaId;

    #[Locked]
    public bool $panelGlobal = false;

    #[Url(except: 'datos')]
    public string $seccion = 'datos';

    public function mount(?Empresa $empresa = null): void
    {
        $actor = $this->actor();
        $this->panelGlobal = $empresa !== null && $actor->esSuperadmin();
        $empresa = $this->panelGlobal ? $empresa : Empresa::query()->findOrFail((int) $actor->empresa_id);
        $this->authorize('facturacion-siat', $empresa);
        $this->empresaId = $empresa->id;
    }

    public function render(): View
    {
        if (! in_array($this->seccion, self::SECCIONES, true)) {
            $this->seccion = 'datos';
        }

        $empresa = Empresa::query()->findOrFail($this->empresaId);
        $this->authorize('facturacion-siat', $empresa);

        return view('livewire.facturacion.panel', ['empresa' => $empresa]);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
