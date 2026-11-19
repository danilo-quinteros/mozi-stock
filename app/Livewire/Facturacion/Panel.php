<?php

namespace App\Livewire\Facturacion;

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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

    /** El plan de la empresa no incluye la facturación: la pestaña solo muestra el aviso para cambiar de plan. */
    #[Locked]
    public bool $sinPlan = false;

    public function mount(?Empresa $empresa = null): void
    {
        $actor = $this->actor();
        $this->panelGlobal = $empresa !== null && $actor->esSuperadmin();
        $empresa = $this->panelGlobal ? $empresa : Empresa::query()->findOrFail((int) $actor->empresa_id);
        $this->empresaId = $empresa->id;

        if (! $this->panelGlobal && Gate::denies('facturacion-siat', $empresa) && Gate::allows('ofrecer-facturacion', $empresa)) {
            $this->sinPlan = true;

            return;
        }

        $this->authorize('facturacion-siat', $empresa);
    }

    public function render(): View
    {
        // Sin la facturación en el plan no se lee nada de SIAT. Si mientras tanto se habilitó, la próxima visita la abre.
        if ($this->sinPlan) {
            return view('livewire.facturacion.sin-plan');
        }

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
