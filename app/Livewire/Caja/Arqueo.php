<?php

namespace App\Livewire\Caja;

use App\Domain\Caja\Consultas\ReporteCaja;
use App\Domain\Reportes\Consultas\Cobros;
use App\Domain\Reportes\Periodo;
use App\Livewire\Concerns\UsaSucursalActiva;
use App\Models\Caja;
use App\Models\Sucursal;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Caja y arqueo (administrador) y Mi caja (vendedor) en la misma ruta.
 *
 * - Administrador: período (día, 7 días o mes que terminan en «fecha»), sucursal y caja; cifras
 *   del período, cajas en este momento, avisos y pestañas de turnos, movimientos, responsables
 *   y días.
 * - Vendedor (decisión del propietario, 01/11/2026): solo su caja asignada, su turno, lo que él
 *   cobró y sus turnos. Los filtros de la URL no se usan en su vista.
 */
#[Title('Caja y arqueo')]
class Arqueo extends Component
{
    use UsaSucursalActiva, WithPagination;

    public const PESTANAS = ['turnos', 'movimientos', 'responsables', 'dias'];

    #[Url(except: '')]
    public string $fecha = '';

    /** '' = un día, '7d' = siete días, 'mes' = desde el día 1 del mes de «fecha». */
    #[Url(except: '')]
    public string $periodo = '';

    #[Url(except: '')]
    public string $sucursal = '';

    #[Url(except: '')]
    public string $caja = '';

    #[Url(except: 'turnos')]
    public string $pestana = 'turnos';

    #[Url(except: '')]
    public string $sentido = '';

    #[Url(except: '')]
    public string $medio = '';

    #[Url(except: '')]
    public string $buscar = '';

    #[Locked]
    public ?int $turnoDetalleId = null;

    public function mount(): void
    {
        $actor = $this->actor();
        abort_unless($actor->esAdministrador() || $actor->esVendedor(), 403);

        if ($actor->esAdministrador()) {
            $this->authorize('ver-reportes');
            $this->aplicarSucursalActiva();
        }

        if ($this->fecha === '') {
            $this->fecha = Periodo::hoy()->desde->format('Y-m-d');
        }
    }

    #[On('turno-modificado')]
    public function refrescar(): void
    {
        // Re-render al abrir o cerrar un turno desde la barra superior.
    }

    public function elegirPeriodo(string $opcion): void
    {
        $hoy = Periodo::hoy()->desde;
        [$this->periodo, $this->fecha] = match ($opcion) {
            'ayer' => ['', $hoy->subDay()->format('Y-m-d')],
            '7d' => ['7d', $hoy->format('Y-m-d')],
            'mes' => ['mes', $hoy->format('Y-m-d')],
            default => ['', $hoy->format('Y-m-d')],
        };
        $this->reiniciarPaginas();
    }

    public function cambiarPestana(string $pestana): void
    {
        $this->pestana = in_array($pestana, self::PESTANAS, true) ? $pestana : 'turnos';
        $this->reiniciarPaginas();
    }

    public function updated(string $propiedad): void
    {
        if ($propiedad === 'fecha') {
            $this->periodo = '';
        }

        if ($propiedad === 'sucursal') {
            $this->caja = '';
        }

        // Lo de fuera de caja no tiene turnos: se muestran sus movimientos.
        if ($propiedad === 'caja' && $this->caja === 'externo' && $this->pestana === 'turnos') {
            $this->pestana = 'movimientos';
        }

        $this->reiniciarPaginas();
    }

    public function verDetalleTurno(int $turnoId): void
    {
        abort_unless($this->turnoVisible($turnoId), 403);
        $this->turnoDetalleId = $turnoId;
    }

    public function cerrarDetalleTurno(): void
    {
        $this->turnoDetalleId = null;
    }

    /** «Cerrar caja» de Mi caja: el cierre lo hace el control de la barra superior. */
    public function cerrarMiCaja(): void
    {
        $this->dispatch('abrir-cierre-caja')->to(ControlCajaTopbar::class);
    }

    /**
     * «Abrir caja» en Cajas en este momento (administrador): cualquier caja activa de su empresa,
     * aunque ya tenga otra abierta. La barra superior muestra el modal y valida al confirmar.
     */
    public function abrirCaja(int $cajaId): void
    {
        abort_unless($this->actor()->esAdministrador(), 403);
        $this->dispatch('abrir-apertura-caja', cajaId: $cajaId)->to(ControlCajaTopbar::class);
    }

    /** «Cerrar caja» en Cajas en este momento: el administrador cierra cualquier turno de su empresa. */
    public function cerrarTurno(int $turnoId): void
    {
        abort_unless($this->actor()->esAdministrador(), 403);
        $this->dispatch('abrir-cierre-caja', turnoId: $turnoId)->to(ControlCajaTopbar::class);
    }

    public function render(): View
    {
        $actor = $this->actor();
        $turnoDetalle = $this->turnoDetalleId !== null && $this->turnoVisible($this->turnoDetalleId)
            ? TurnoCaja::withoutGlobalScopes()->where('empresa_id', $actor->empresa_id)
                ->with(['caja', 'sucursal', 'usuarioApertura', 'usuarioCierre', 'participantes.usuario'])
                ->find($this->turnoDetalleId)
            : null;
        $comunes = [
            'zona' => Periodo::zona(),
            'turnoDetalle' => $turnoDetalle,
            'arqueoDetalle' => $turnoDetalle?->calcularArqueo(),
        ];

        return $actor->esAdministrador()
            ? view('livewire.caja.arqueo', [...$comunes, ...$this->datosAdministrador($actor)])
            : view('livewire.caja.mi-caja', [...$comunes, ...$this->datosVendedor($actor)])->title(__('Mi caja'));
    }

    /**
     * @return array<string, mixed>
     */
    private function datosAdministrador(User $actor): array
    {
        $empresaId = (int) $actor->empresa_id;
        $periodo = $this->periodoElegido();
        $sucursales = Sucursal::query()->where('empresa_id', $empresaId)->where('activa', true)->orderBy('nombre')->get(['id', 'nombre']);
        $sucursalId = ctype_digit($this->sucursal) ? $sucursales->firstWhere('id', (int) $this->sucursal)?->id : null;
        $cajas = Caja::query()->where('empresa_id', $empresaId)->where('activa', true)
            ->when($sucursalId !== null, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->with('sucursal:id,nombre')
            ->orderBy('sucursal_id')->orderByDesc('es_principal')->orderBy('nombre')
            ->get(['id', 'sucursal_id', 'nombre']);
        $cajaId = $this->caja === 'externo' ? ReporteCaja::FUERA_DE_CAJA
            : (ctype_digit($this->caja) ? $cajas->firstWhere('id', (int) $this->caja)?->id : null);
        $pestana = in_array($this->pestana, self::PESTANAS, true) ? $this->pestana : 'turnos';

        $datos = [
            'periodoActual' => $periodo,
            'sucursales' => $sucursales,
            'cajas' => $cajas,
            'resumen' => Cobros::resumen($empresaId, $periodo, $sucursalId, '', $cajaId),
            'diferencias' => ReporteCaja::diferencias($empresaId, $periodo, $sucursalId, $cajaId),
            'avisos' => ReporteCaja::avisos($empresaId, $periodo, $sucursalId, $cajaId),
            'cajasAhora' => ReporteCaja::cajasAhora($empresaId, $sucursalId, $cajaId),
            'pestanaActiva' => $pestana,
        ];

        return $datos + match ($pestana) {
            'movimientos' => [
                'movimientos' => ReporteCaja::movimientos($empresaId, $periodo, $sucursalId, $cajaId,
                    $this->sentido, $this->medio, $this->buscar)->paginate(25, pageName: 'movimientos'),
            ],
            'responsables' => ['responsables' => ReporteCaja::porResponsable($empresaId, $periodo, $sucursalId, $cajaId)],
            'dias' => $this->datosPorDia($empresaId, $periodo, $sucursalId, $cajaId),
            default => $this->datosTurnos($empresaId, $periodo, $sucursalId, $cajaId),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function datosTurnos(int $empresaId, Periodo $periodo, ?int $sucursalId, ?int $cajaId): array
    {
        $turnos = ReporteCaja::turnos($empresaId, $periodo, $sucursalId, $cajaId)->paginate(15, pageName: 'turnos');
        $abiertos = array_values($turnos->getCollection()->filter(fn (TurnoCaja $t) => $t->estaAbierto())->map(fn (TurnoCaja $t): int => $t->id)->all());

        return ['turnos' => $turnos, 'totalesTurnos' => ReporteCaja::totalesTurnos($empresaId, $abiertos)];
    }

    /**
     * Un día se muestra con sus seis días anteriores; 7 días o un mes, tal cual.
     *
     * @return array<string, mixed>
     */
    private function datosPorDia(int $empresaId, Periodo $periodo, ?int $sucursalId, ?int $cajaId): array
    {
        $rango = $periodo->esUnDia() ? Periodo::rango($periodo->desde->subDays(6)->format('Y-m-d'), $periodo->hasta->format('Y-m-d')) : $periodo;
        $dias = ReporteCaja::porDia($empresaId, $rango, $sucursalId, $cajaId);
        $maximo = array_reduce($dias, fn (string $max, array $d) => Decimal::comparar($d['entradas'], $max, 2) > 0 ? $d['entradas'] : $max, '0');
        $barras = array_map(fn (array $d) => [
            'etiqueta' => $d['fecha']->format('d'),
            'titulo' => $d['fecha']->format('d/m').' · '.Decimal::bs($d['entradas']),
            'alto' => Decimal::comparar($maximo, '0', 2) > 0 ? (int) Decimal::dividir(Decimal::multiplicar($d['entradas'], '100', 2), $maximo, 0) : 0,
        ], array_reverse($dias));
        $porSucursal = array_values(array_filter(Cobros::porSucursal($empresaId, $periodo),
            fn (\stdClass $s) => $sucursalId === null || (int) $s->id === $sucursalId));

        return ['dias' => $dias, 'barras' => $barras, 'rangoDias' => $rango, 'porSucursal' => $porSucursal,
            'totalEntradas' => array_reduce($porSucursal, fn (string $t, \stdClass $s) => Decimal::sumar($t, (string) $s->total, 2), '0.00')];
    }

    /**
     * @return array<string, mixed>
     */
    private function datosVendedor(User $actor): array
    {
        $empresaId = (int) $actor->empresa_id;
        $caja = $actor->caja_id === null ? null : Caja::query()->where('empresa_id', $empresaId)
            ->where('sucursal_id', $actor->sucursal_id)->with('sucursal:id,nombre')->find($actor->caja_id);
        $turno = $caja?->turnoActivo?->load(['usuarioApertura:id,name', 'participantes.usuario:id,name']);
        $operable = $turno !== null && $turno->usuarioPuedeOperar($actor);
        $totales = $operable ? ReporteCaja::totalesTurnos($empresaId, [$turno->id])[$turno->id] : null;
        $mios = $operable ? ReporteCaja::totalesTurnos($empresaId, [$turno->id], $actor->id)[$turno->id] : null;
        $misTurnos = ReporteCaja::turnosDeUsuario($actor)->where('estado', 'cerrado')
            ->with('caja:id,nombre')->orderByDesc('fecha_apertura')->limit(8)->get();

        return [
            'miCaja' => $caja,
            'turno' => $turno,
            'operable' => $operable,
            'totales' => $totales,
            'mios' => $mios,
            'esperado' => $operable ? ReporteCaja::esperado($turno, $totales) : null,
            'misMovimientos' => $operable ? ReporteCaja::movimientos($empresaId, usuarioId: $actor->id, turnoId: $turno->id)->paginate(25, pageName: 'cobros') : null,
            'misTurnos' => $misTurnos,
            'cobradoPorMi' => ReporteCaja::totalesTurnos($empresaId, array_values($misTurnos->map(fn (TurnoCaja $t): int => $t->id)->all()), $actor->id),
        ];
    }

    private function periodoElegido(): Periodo
    {
        $dia = Periodo::dia($this->fecha);

        return match ($this->periodo) {
            '7d' => Periodo::rango($dia->desde->subDays(6)->format('Y-m-d'), $dia->desde->format('Y-m-d')),
            'mes' => Periodo::rango($dia->desde->startOfMonth()->format('Y-m-d'), $dia->desde->format('Y-m-d')),
            default => $dia,
        };
    }

    /**
     * Administrador: turnos de su empresa (de su sucursal si la tiene fija). Vendedor: solo
     * los turnos que abrió, en los que participó o en los que registró dinero.
     */
    private function turnoVisible(int $turnoId): bool
    {
        $actor = $this->actor();

        if ($actor->esAdministrador()) {
            return TurnoCaja::withoutGlobalScopes()->where('empresa_id', $actor->empresa_id)->whereKey($turnoId)
                ->when($this->sucursalFija, fn ($q) => $q->where('sucursal_id', (int) $this->sucursal))
                ->exists();
        }

        return ReporteCaja::turnosDeUsuario($actor)->whereKey($turnoId)->exists();
    }

    private function reiniciarPaginas(): void
    {
        foreach (['turnos', 'movimientos', 'cobros'] as $pagina) {
            $this->resetPage($pagina);
        }
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
