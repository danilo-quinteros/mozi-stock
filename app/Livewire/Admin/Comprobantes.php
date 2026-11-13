<?php

namespace App\Livewire\Admin;

use App\Domain\Suscripciones\Actions\RevisarComprobantePago;
use App\Domain\Suscripciones\CalendarioSuscripcion;
use App\Domain\Suscripciones\Consultas\ListadoComprobantes;
use App\Enums\EstadoPagoSaas;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\PagoSaas;
use App\Models\Scopes\EmpresaScope;
use App\Models\Suscripcion;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Revisión manual de comprobantes (comprobantes.html). Aprobar renueva conforme a § 8.4 y
 * exige confirmar el importe exacto; rechazar exige un motivo visible para la empresa.
 */
#[Title('Comprobantes de pago')]
class Comprobantes extends Component
{
    use MuestraAvisos, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    #[Url(except: '')]
    public string $estado = '';

    #[Locked]
    public ?int $revisandoId = null;

    public bool $verificado = false;

    public bool $rechazando = false;

    public string $motivo = '';

    public function mount(): void
    {
        $this->authorize('revisar', PagoSaas::class);

        if (! in_array($this->estado, ListadoComprobantes::ESTADOS, true)) {
            $this->estado = '';
        }
    }

    public function updated(string $propiedad): void
    {
        if (in_array($propiedad, ['buscar', 'estado'], true)) {
            $this->resetPage();
        }
    }

    public function revisar(int $id): void
    {
        $this->authorize('revisar', PagoSaas::class);
        $this->revisandoId = PagoSaas::withoutGlobalScope(EmpresaScope::class)->findOrFail($id)->id;
        $this->reset('verificado', 'rechazando', 'motivo');
        $this->resetValidation();
    }

    public function aprobar(RevisarComprobantePago $accion): void
    {
        if ($this->revisandoId === null) {
            return;
        }

        $pago = $accion->aprobar($this->actor(), $this->revisandoId, $this->verificado);
        $this->cerrar();
        $this->avisar(__(':codigo aprobado: el nuevo vencimiento es el :format.', ['codigo' => $pago->codigo(), 'format' => $pago->vencimiento_nuevo?->format('d/m/Y')]));
    }

    public function rechazar(RevisarComprobantePago $accion): void
    {
        if ($this->revisandoId === null) {
            return;
        }

        if (! $this->rechazando) {
            $this->rechazando = true;

            return;
        }

        $pago = $accion->rechazar($this->actor(), $this->revisandoId, $this->motivo);
        $this->cerrar();
        $this->avisar(__(':codigo rechazado. La empresa verá el motivo y podrá enviar otro.', ['codigo' => $pago->codigo()]));
    }

    public function cerrar(): void
    {
        $this->revisandoId = null;
        $this->reset('verificado', 'rechazando', 'motivo');
        $this->resetValidation();
    }

    public function render(): View
    {
        $revisando = $this->revisandoId === null ? null
            : PagoSaas::withoutGlobalScope(EmpresaScope::class)->with(['empresa:id,nombre,activa', 'remitente:id,name,email', 'revisor:id,name'])->find($this->revisandoId);
        $suscripcion = $revisando === null ? null
            : Suscripcion::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $revisando->empresa_id)->find($revisando->suscripcion_id);

        return view('livewire.admin.comprobantes', [
            'comprobantes' => ListadoComprobantes::consulta($this->buscar, $this->estado)->paginate(25),
            'conteos' => ListadoComprobantes::conteosDelMes(),
            'revisando' => $revisando,
            'suscripcion' => $suscripcion,
            'hoy' => CalendarioSuscripcion::hoy(),
            'proyectado' => $revisando?->estado === EstadoPagoSaas::Pendiente && ! $revisando->esExtras() ? $suscripcion?->vencimientoTras((int) $revisando->meses) : null,
            'importeTexto' => $revisando === null ? null : Decimal::bs((string) $revisando->importe),
        ]);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
