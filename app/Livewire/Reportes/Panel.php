<?php

namespace App\Livewire\Reportes;

use App\Domain\Creditos\Consultas\ListadoCreditos;
use App\Domain\Reportes\Consultas\Cobros;
use App\Domain\Reportes\Consultas\InventarioSucursales;
use App\Domain\Reportes\Consultas\VentasPeriodo;
use App\Domain\Reportes\Periodo;
use App\Livewire\Concerns\UsaSucursalActiva;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Reportes mínimos (§ 3.11): cobros por fecha real, ventas confirmadas (valor vendido como
 * dato comercial separado), inventario por sucursal y créditos. Cada pestaña exporta CSV
 * con su misma consulta y filtros.
 */
#[Title('Reportes generales')]
class Panel extends Component
{
    use UsaSucursalActiva, WithPagination;

    public const PESTANAS = ['cobros', 'ventas', 'inventario', 'creditos'];

    #[Url(except: 'cobros')]
    public string $pestana = 'cobros';

    #[Url(except: '')]
    public string $desde = '';

    #[Url(except: '')]
    public string $hasta = '';

    #[Url(except: '')]
    public string $sucursal = '';

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    public bool $soloAlertas = false;

    public function mount(): void
    {
        $this->authorize('ver-reportes');
        $this->aplicarSucursalActiva();
        $hoy = Periodo::hoy()->desde->format('Y-m-d');
        $this->desde = $this->desde === '' ? $hoy : $this->desde;
        $this->hasta = $this->hasta === '' ? $hoy : $this->hasta;

        if (! in_array($this->pestana, self::PESTANAS, true)) {
            $this->pestana = 'cobros';
        }
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        /** @var User $actor */
        $actor = Auth::user();
        $empresaId = (int) $actor->empresa_id;
        $periodo = Periodo::rango($this->desde, $this->hasta);
        $sucursalId = ctype_digit($this->sucursal) ? (int) $this->sucursal : null;

        $filas = match ($this->pestana) {
            'ventas' => VentasPeriodo::consulta($empresaId, $periodo, $sucursalId, $this->buscar)->paginate(25),
            'inventario' => InventarioSucursales::consulta($empresaId, $sucursalId, $this->buscar, $this->soloAlertas)->paginate(25),
            'creditos' => ListadoCreditos::consulta($empresaId, 'pendientes', $sucursalId, $this->buscar)->paginate(25),
            default => Cobros::detalle($empresaId, $periodo, $sucursalId, '', $this->buscar)->paginate(25),
        };

        return view('livewire.reportes.panel', [
            'periodo' => $periodo,
            'cobros' => Cobros::resumen($empresaId, $periodo, $sucursalId),
            'ventas' => VentasPeriodo::totales($empresaId, $periodo, $sucursalId),
            'pendientes' => VentasPeriodo::pendientes($empresaId, $sucursalId),
            'filas' => $filas,
            'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre']),
            'filtrosCsv' => array_filter(['pestana' => $this->pestana, 'desde' => $periodo->desde->format('Y-m-d'),
                'hasta' => $periodo->hasta->format('Y-m-d'), 'sucursal' => $this->sucursal, 'q' => trim($this->buscar),
                'alertas' => $this->pestana === 'inventario' && $this->soloAlertas ? '1' : null]),
        ]);
    }
}
