<?php

namespace App\Livewire\Reportes;

use App\Domain\Creditos\Consultas\CuentasPorCobrar;
use App\Domain\Reportes\Consultas\Cobros;
use App\Domain\Reportes\Consultas\InventarioSucursales;
use App\Domain\Reportes\Consultas\VentasPeriodo;
use App\Domain\Reportes\Periodo;
use App\Livewire\Concerns\UsaSucursalActiva;
use App\Models\Sucursal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Reportes mínimos (§ 3.11): cobros por fecha real, ventas confirmadas (valor vendido como
 * dato comercial separado, menos las devoluciones del período = venta neta), inventario por
 * sucursal y créditos. Cada pestaña exporta CSV con su misma consulta y filtros.
 *
 * Rango de fechas: preajustes («Este mes hasta hoy» por defecto) o un rango personalizado. La elección se guarda en
 * una cookie del navegador (un año) y se recupera al volver; el preajuste se recalcula cada día.
 */
#[Title('Reportes generales')]
class Panel extends Component
{
    use UsaSucursalActiva, WithPagination;

    public const PESTANAS = ['cobros', 'ventas', 'inventario', 'creditos'];

    public const COOKIE_RANGO = 'reportes_rango';

    /** La cookie del rango dura un año. */
    public const MINUTOS_COOKIE = 60 * 24 * 365;

    #[Url(except: 'cobros')]
    public string $pestana = 'cobros';

    /** Preajuste elegido (clave de Periodo::PREAJUSTES) o «personalizado». Solo lo cambia el servidor. */
    #[Locked]
    public string $rango = 'mes';

    /** Fechas Y-m-d del rango que se consulta. */
    public string $desde = '';

    public string $hasta = '';

    /** Borrador de la pestaña «Personalizado»: se aplica con «Aplicar». */
    public string $personalDesde = '';

    public string $personalHasta = '';

    #[Url(except: '')]
    public string $sucursal = '';

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    /** Créditos: por documento o saldos por cliente (§ 10 de cuentas por cobrar). */
    #[Url(except: 'documento')]
    public string $agrupar = 'documento';

    /** Ventas: las ventas del período o las devoluciones registradas en él. */
    #[Url(except: 'ventas')]
    public string $vista = 'ventas';

    #[Url(as: 'alertas', except: false)]
    public bool $soloAlertas = false;

    public function mount(): void
    {
        $this->authorize('ver-reportes');
        $this->aplicarSucursalActiva();
        $this->cargarRango();

        if (! in_array($this->pestana, self::PESTANAS, true)) {
            $this->pestana = 'cobros';
        }

        if ($this->agrupar !== 'cliente') {
            $this->agrupar = 'documento';
        }

        if ($this->vista !== 'devoluciones') {
            $this->vista = 'ventas';
        }
    }

    public function updated(string $propiedad): void
    {
        // Fechas puestas directamente: es un rango personalizado.
        if (in_array($propiedad, ['desde', 'hasta'], true)) {
            $this->usarPeriodo('personalizado', Periodo::rango($this->desde, $this->hasta));
            $this->guardarRango();
        }

        if (! str_starts_with($propiedad, 'personal')) {
            $this->resetPage();
        }
    }

    /** Preajuste del selector: se aplica al tocarlo y se guarda en la cookie. */
    public function elegirRango(string $clave): void
    {
        $periodo = Periodo::preajuste($clave);

        if ($periodo === null) {
            return;
        }

        $this->usarPeriodo($clave, $periodo);
        $this->guardarRango();
        $this->resetPage();
    }

    /** «Aplicar» de la pestaña Personalizado: fechas válidas, en cualquier orden, de hasta un año. */
    public function aplicarPersonalizado(): void
    {
        $this->validate([
            'personalDesde' => ['required', 'date_format:Y-m-d'],
            'personalHasta' => ['required', 'date_format:Y-m-d'],
        ], [
            'personalDesde.required' => __('Elige la fecha de inicio.'),
            'personalHasta.required' => __('Elige la fecha de fin.'),
            'personalDesde.date_format' => __('Fecha no válida.'),
            'personalHasta.date_format' => __('Fecha no válida.'),
        ]);

        $inicio = CarbonImmutable::createFromFormat('!Y-m-d', $this->personalDesde, Periodo::zona());
        $fin = CarbonImmutable::createFromFormat('!Y-m-d', $this->personalHasta, Periodo::zona());

        if ($inicio === null || $fin === null) {
            throw ValidationException::withMessages(['personalDesde' => __('Fecha no válida.')]);
        }

        if (abs($inicio->diffInDays($fin)) > Periodo::MAX_DIAS) {
            throw ValidationException::withMessages(['personalHasta' => __('Elige un rango de hasta un año.')]);
        }

        $this->usarPeriodo('personalizado', Periodo::rango($this->personalDesde, $this->personalHasta));
        $this->guardarRango();
        $this->resetPage();
        $this->dispatch('rango-aplicado');
    }

    public function render(): View
    {
        /** @var User $actor */
        $actor = Auth::user();
        $empresaId = (int) $actor->empresa_id;
        $periodo = Periodo::rango($this->desde, $this->hasta);
        $sucursalId = ctype_digit($this->sucursal) ? (int) $this->sucursal : null;

        $filas = match ($this->pestana) {
            'ventas' => $this->vista === 'devoluciones'
                ? VentasPeriodo::devoluciones($empresaId, $periodo, $sucursalId, $this->buscar)->paginate(25)
                : VentasPeriodo::consulta($empresaId, $periodo, $sucursalId, $this->buscar)->paginate(25),
            'inventario' => InventarioSucursales::consulta($empresaId, $sucursalId, $this->buscar, $this->soloAlertas)->paginate(25),
            'creditos' => $this->agrupar === 'cliente'
                ? CuentasPorCobrar::porCliente($empresaId, $sucursalId, $this->buscar)->paginate(25)
                : CuentasPorCobrar::consulta($empresaId, 'abiertas', $sucursalId, $this->buscar)->paginate(25),
            default => Cobros::detalle($empresaId, $periodo, $sucursalId, '', $this->buscar)->paginate(25),
        };

        $preajustes = [];

        foreach (Periodo::PREAJUSTES as $clave => $etiqueta) {
            $preajustes[$clave] = ['etiqueta' => __($etiqueta), 'texto' => self::textoRango(Periodo::preajuste($clave) ?? $periodo)];
        }

        return view('livewire.reportes.panel', [
            'periodo' => $periodo,
            'preajustes' => $preajustes,
            'etiquetaRango' => $preajustes[$this->rango]['etiqueta'] ?? __('Personalizado'),
            'textoRango' => self::textoRango($periodo),
            'hoy' => Periodo::hoy()->desde->format('Y-m-d'),
            'cobros' => Cobros::resumen($empresaId, $periodo, $sucursalId),
            'ventas' => VentasPeriodo::totales($empresaId, $periodo, $sucursalId),
            'pendientes' => VentasPeriodo::pendientes($empresaId, $sucursalId),
            'filas' => $filas,
            'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre']),
            'filtrosCsv' => array_filter(['pestana' => $this->pestana, 'desde' => $periodo->desde->format('Y-m-d'),
                'hasta' => $periodo->hasta->format('Y-m-d'), 'sucursal' => $this->sucursal, 'q' => trim($this->buscar),
                'alertas' => $this->pestana === 'inventario' && $this->soloAlertas ? '1' : null,
                'agrupar' => $this->pestana === 'creditos' && $this->agrupar === 'cliente' ? 'cliente' : null,
                'vista' => $this->pestana === 'ventas' && $this->vista === 'devoluciones' ? 'devoluciones' : null]),
        ]);
    }

    /**
     * Rango guardado en la cookie: una clave de preajuste o «personalizado|Y-m-d|Y-m-d». Lo que no se reconoce vuelve
     * al predeterminado, «Este mes hasta hoy»; las fechas pasan por Periodo::rango (ordena y limita a un año).
     */
    private function cargarRango(): void
    {
        $guardado = request()->cookie(self::COOKIE_RANGO);
        $guardado = is_string($guardado) ? $guardado : '';

        if (str_starts_with($guardado, 'personalizado|')) {
            [, $desde, $hasta] = array_pad(explode('|', $guardado, 3), 3, '');
            $this->usarPeriodo('personalizado', Periodo::rango($desde, $hasta));

            return;
        }

        $clave = array_key_exists($guardado, Periodo::PREAJUSTES) ? $guardado : 'mes';
        $this->usarPeriodo($clave, Periodo::preajuste($clave) ?? Periodo::mesActual());
    }

    private function usarPeriodo(string $rango, Periodo $periodo): void
    {
        $this->rango = $rango;
        $this->desde = $this->personalDesde = $periodo->desde->format('Y-m-d');
        $this->hasta = $this->personalHasta = $periodo->hasta->format('Y-m-d');
    }

    private function guardarRango(): void
    {
        $valor = $this->rango === 'personalizado' ? "personalizado|{$this->desde}|{$this->hasta}" : $this->rango;
        Cookie::queue(self::COOKIE_RANGO, $valor, self::MINUTOS_COOKIE);
    }

    /**
     * «18 nov 2026», «1 – 18 nov 2026», «1 oct – 18 nov 2026» o «15 dic 2025 – 3 ene 2026».
     */
    private static function textoRango(Periodo $periodo): string
    {
        $mes = fn (CarbonImmutable $fecha): string => rtrim($fecha->settings(['locale' => app()->getLocale()])->translatedFormat('M'), '.');
        [$desde, $hasta] = [$periodo->desde, $periodo->hasta];
        $fin = $hasta->day.' '.$mes($hasta).' '.$hasta->year;

        return match (true) {
            $periodo->esUnDia() => $fin,
            $desde->isSameMonth($hasta) => $desde->day.' – '.$fin,
            $desde->isSameYear($hasta) => $desde->day.' '.$mes($desde).' – '.$fin,
            default => $desde->day.' '.$mes($desde).' '.$desde->year.' – '.$fin,
        };
    }
}
