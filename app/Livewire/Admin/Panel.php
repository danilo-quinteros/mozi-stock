<?php

namespace App\Livewire\Admin;

use App\Domain\Suscripciones\CalendarioSuscripcion;
use App\Domain\Suscripciones\Consultas\ListadoSuscripciones;
use App\Models\Empresa;
use App\Models\Scopes\EmpresaScope;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Panel general')]
class Panel extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', Empresa::class);
    }

    public function render(): View
    {
        $hoy = CalendarioSuscripcion::hoy();
        $conteo = Empresa::query()
            ->selectRaw('count(*) as registradas')
            ->selectRaw('sum(case when activa = 1 then 1 else 0 end) as activas')
            ->toBase()
            ->first();

        return view('livewire.admin.panel', [
            'conteo' => $conteo,
            'suscripciones' => ListadoSuscripciones::conteos($hoy),
            'hoy' => $hoy,
            // Vencimientos más próximos primero (los vencidos antes) y luego las empresas sin plan.
            // Solo datos del plan, nada comercial.
            'proximas' => ListadoSuscripciones::consulta('', '', $hoy)->limit(6)->get(),
            'sinPlan' => Empresa::query()->whereDoesntHave('suscripcion', fn ($q) => $q->withoutGlobalScope(EmpresaScope::class))->count(),
        ]);
    }
}
