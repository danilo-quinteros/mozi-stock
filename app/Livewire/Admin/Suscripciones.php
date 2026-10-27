<?php

namespace App\Livewire\Admin;

use App\Domain\Reportes\Periodo;
use App\Domain\Suscripciones\Actions\GuardarPlan;
use App\Domain\Suscripciones\CalendarioSuscripcion;
use App\Domain\Suscripciones\Consultas\ListadoComprobantes;
use App\Domain\Suscripciones\Consultas\ListadoSuscripciones;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Plan;
use App\Models\Suscripcion;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Suscripciones de todas las empresas y gestión de planes SaaS de MoziStudios.
 * El superadministrador puede editar el título, la descripción y el precio de cada plan,
 * o agregar un tercer plan según se requiera.
 */
#[Title('Suscripciones')]
class Suscripciones extends Component
{
    use MuestraAvisos, WithPagination;

    #[Url(except: 'empresas')]
    public string $pestana = 'empresas';

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    #[Url(except: '')]
    public string $estado = '';

    // Modal de edición / creación de planes
    public bool $modalPlanAbierto = false;

    #[Locked]
    public ?int $planEditandoId = null;

    public string $planTitulo = '';

    public string $planDescripcion = '';

    public string $planPrecio = '';

    public bool $planSiat = false;

    public function mount(): void
    {
        $this->authorize('configurar', Suscripcion::class);

        if (! in_array($this->pestana, ['empresas', 'recordatorios', 'planes'], true)) {
            $this->pestana = 'empresas';
        }

        if (! in_array($this->estado, ListadoSuscripciones::ESTADOS, true)) {
            $this->estado = '';
        }
    }

    public function updated(string $propiedad): void
    {
        if (in_array($propiedad, ['buscar', 'estado', 'pestana'], true)) {
            $this->resetPage();
        }
    }

    public function crearPlan(): void
    {
        $this->authorize('gestionarPlanes', Suscripcion::class);
        $this->resetValidation();
        $this->planEditandoId = null;
        $this->planTitulo = '';
        $this->planDescripcion = '';
        $this->planPrecio = '';
        $this->planSiat = false;
        $this->modalPlanAbierto = true;
    }

    public function editarPlan(int $id): void
    {
        $this->authorize('gestionarPlanes', Suscripcion::class);
        $this->resetValidation();
        $plan = Plan::query()->findOrFail($id);
        $this->planEditandoId = $plan->id;
        $this->planTitulo = $plan->titulo;
        $this->planDescripcion = $plan->descripcion;
        $this->planPrecio = Decimal::simple((string) $plan->precio_mensual);
        $this->planSiat = $plan->siat_habilitado;
        $this->modalPlanAbierto = true;
    }

    public function cerrarModalPlan(): void
    {
        $this->modalPlanAbierto = false;
        $this->resetValidation();
        $this->reset('planEditandoId', 'planTitulo', 'planDescripcion', 'planPrecio', 'planSiat');
    }

    public function guardarPlan(GuardarPlan $accion): void
    {
        $this->authorize('gestionarPlanes', Suscripcion::class);

        $this->validate([
            'planTitulo' => ['required', 'string', 'max:150'],
            'planDescripcion' => ['required', 'string', 'max:1000'],
            'planPrecio' => ['required', 'regex:/^\d{1,15}([.,]\d{1,2})?$/'],
            'planSiat' => ['boolean'],
        ], [
            'planTitulo.required' => __('Indica el título del plan.'),
            'planDescripcion.required' => __('Indica la descripción del plan.'),
            'planPrecio.required' => __('Indica el precio mensual.'),
            'planPrecio.regex' => __('El precio mensual admite hasta 2 decimales.'),
        ], [
            'planTitulo' => __('título'),
            'planDescripcion' => __('descripción'),
            'planPrecio' => __('precio mensual'),
        ]);

        $plan = $accion->ejecutar($this->actor(), [
            'titulo' => $this->planTitulo,
            'descripcion' => $this->planDescripcion,
            'precio_mensual' => $this->planPrecio,
            'siat_habilitado' => $this->planSiat,
        ], $this->planEditandoId);

        $this->cerrarModalPlan();
        $this->avisar($this->planEditandoId ? __('Plan :titulo actualizado correctamente.', ['titulo' => $plan->titulo]) : __('Nuevo plan :titulo registrado correctamente.', ['titulo' => $plan->titulo]));
    }

    public function render(): View
    {
        $hoy = CalendarioSuscripcion::hoy();
        $filas = $this->pestana === 'empresas' ? ListadoSuscripciones::consulta($this->buscar, $this->estado, $hoy)->paginate(25) : null;
        $planes = $this->pestana === 'planes' ? Plan::query()->withCount('suscripciones')->orderBy('orden')->get() : collect();

        return view('livewire.admin.suscripciones', [
            'hoy' => $hoy,
            'filas' => $filas,
            'planes' => $planes,
            'ultimosAvisos' => $filas === null ? [] : ListadoSuscripciones::ultimosAvisos($filas->items()),
            'avisos' => $this->pestana === 'recordatorios' ? ListadoComprobantes::avisos($this->buscar)->paginate(25) : null,
            'conteos' => ListadoSuscripciones::conteos($hoy),
            'cobradoMes' => ListadoSuscripciones::cobrado(Periodo::mesActual()),
        ]);
    }

    private function actor(): User
    {
        /** @var User */
        return auth()->user();
    }
}
