<?php

namespace App\Livewire\Produccion;

use App\Domain\Produccion\Consultas\ReporteProduccion;
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
 * Producción → Reportes (ESPECIFICACION_PRODUCCION.md § 12, etapa P4): producción del período por producto, rendimiento
 * y costo de cada orden, y consumo de materias primas, todo sobre las órdenes finalizadas en el rango. Cada pestaña
 * exporta CSV con la misma consulta y filtros. Solo el administrador con la capacidad (ruta + gate usar-produccion).
 */
#[Title('Reportes de producción')]
class Reportes extends Component
{
    use UsaSucursalActiva, WithPagination;

    #[Url(except: 'productos')]
    public string $pestana = 'productos';

    #[Url(except: '')]
    public string $desde = '';

    #[Url(except: '')]
    public string $hasta = '';

    #[Url(except: '')]
    public string $sucursal = '';

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    public function mount(): void
    {
        $this->authorize('usar-produccion');
        $this->aplicarSucursalActiva();
        // La producción no es diaria como la venta: por defecto, el mes en curso.
        $mes = Periodo::mesActual();
        $this->desde = $this->desde === '' ? $mes->desde->format('Y-m-d') : $this->desde;
        $this->hasta = $this->hasta === '' ? $mes->hasta->format('Y-m-d') : $this->hasta;

        if (! in_array($this->pestana, ReporteProduccion::PESTANAS, true)) {
            $this->pestana = 'productos';
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
        $sucursalId = $this->sucursalFiltro();
        $pestana = in_array($this->pestana, ReporteProduccion::PESTANAS, true) ? $this->pestana : 'productos';

        $filas = match ($pestana) {
            'ordenes' => ReporteProduccion::porOrden($empresaId, $periodo, $sucursalId, $this->buscar),
            'materias' => ReporteProduccion::materias($empresaId, $periodo, $sucursalId, $this->buscar),
            default => ReporteProduccion::porProducto($empresaId, $periodo, $sucursalId, $this->buscar),
        };

        return view('livewire.produccion.reportes', [
            'pestanaActiva' => $pestana,
            'periodo' => $periodo,
            'resumen' => ReporteProduccion::resumen($empresaId, $periodo, $sucursalId),
            'filas' => $filas->paginate(25),
            'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre']),
            'filtrosCsv' => array_filter([
                'pestana' => $pestana === 'productos' ? null : $pestana,
                'desde' => $periodo->desde->format('Y-m-d'),
                'hasta' => $periodo->hasta->format('Y-m-d'),
                'sucursal' => $sucursalId === null ? null : (string) $sucursalId,
                'q' => trim($this->buscar),
            ]),
        ]);
    }
}
