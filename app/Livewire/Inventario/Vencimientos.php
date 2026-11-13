<?php

namespace App\Livewire\Inventario;

use App\Domain\Inventario\Actions\RegularizarLote;
use App\Domain\Inventario\Consultas\ReporteVencimientos;
use App\Domain\Inventario\DatosLote;
use App\Livewire\Concerns\MuestraAvisos;
use App\Livewire\Concerns\UsaSucursalActiva;
use App\Models\OperacionInventario;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Reporte «Vencimientos» (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 4 y § 9.3), bajo Inventario. Solo con la capacidad
 * (middleware de la ruta) y con la misma Policy que el historial de inventario (§ 8): el administrador.
 *
 * Desde «Sin fecha» se regulariza: el stock del lote de regularización de un producto en una sucursal pasa a uno o
 * más lotes reales con su número y vencimiento (RegularizarLote, una operación de ajuste con nota obligatoria).
 */
#[Title('Vencimientos')]
class Vencimientos extends Component
{
    use MuestraAvisos, UsaSucursalActiva, WithPagination;

    #[Url(except: 'todos')]
    public string $estado = 'todos';

    #[Url(as: 'sucursal', except: '')]
    public string $sucursal = '';

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    /** Regularización abierta: sucursal y producto del stock «Sin fecha» (los fija el servidor al abrirla). */
    #[Locked]
    public ?int $regSucursalId = null;

    #[Locked]
    public ?int $regProductoId = null;

    #[Locked]
    public string $regClave = '';

    /** @var list<array{lote: string, vencimiento: string, cantidad: string}> */
    public array $regLineas = [];

    public string $regNota = '';

    public function mount(): void
    {
        $this->authorize('viewAny', OperacionInventario::class);
        $this->aplicarSucursalActiva();
    }

    public function updated(string $propiedad): void
    {
        if (in_array($propiedad, ['estado', 'sucursal', 'buscar'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return array{estado: string, sucursal_id: int|null, buscar: string}
     */
    public function filtros(): array
    {
        return [
            'estado' => in_array($this->estado, ReporteVencimientos::ESTADOS, true) ? $this->estado : 'todos',
            'sucursal_id' => ctype_digit($this->sucursal) ? (int) $this->sucursal : null,
            'buscar' => $this->buscar,
        ];
    }

    /**
     * Abre la regularización del stock «Sin fecha» de un producto en una sucursal. Lo que llega del navegador solo
     * elige la fila: la acción vuelve a validar empresa, sucursal, producto y saldo.
     */
    public function regularizar(int $sucursalId, int $productoId): void
    {
        $this->authorize('create', OperacionInventario::class);

        if (! Sucursal::query()->whereKey($sucursalId)->exists() || ! Producto::query()->whereKey($productoId)->where('controla_vencimiento', true)->exists()) {
            return;
        }

        $this->resetValidation();
        $this->regSucursalId = $sucursalId;
        $this->regProductoId = $productoId;
        $this->regClave = (string) Str::uuid();
        $this->regLineas = [['lote' => '', 'vencimiento' => '', 'cantidad' => Decimal::simple($this->saldoSinFecha())]];
        $this->regNota = '';
    }

    public function otroLote(): void
    {
        if ($this->regProductoId !== null && count($this->regLineas) < 20) {
            $this->regLineas[] = ['lote' => '', 'vencimiento' => '', 'cantidad' => ''];
        }
    }

    public function quitarLinea(int $indice): void
    {
        if (count($this->regLineas) > 1 && isset($this->regLineas[$indice])) {
            $lineas = $this->regLineas;
            unset($lineas[$indice]);
            $this->regLineas = array_values($lineas);
        }
    }

    public function cerrarRegularizacion(): void
    {
        $this->regSucursalId = null;
        $this->regProductoId = null;
        $this->regLineas = [];
        $this->resetValidation();
    }

    public function confirmarRegularizacion(RegularizarLote $accion): void
    {
        if ($this->regSucursalId === null || $this->regProductoId === null) {
            return;
        }

        $this->validate(['regNota' => ['required', 'string', 'max:250']], [], ['regNota' => __('nota')]);

        $operacion = $accion->ejecutar($this->actor(), [
            'sucursal_id' => $this->regSucursalId,
            'producto_id' => $this->regProductoId,
            'nota' => $this->regNota,
            'lineas' => $this->regLineas,
            'clave' => $this->regClave,
        ]);

        $this->cerrarRegularizacion();
        $this->avisar(__(':codigo registrado: el stock quedó en sus lotes.', ['codigo' => $operacion->codigo()]));
    }

    public function render(): View
    {
        $empresaId = (int) $this->actor()->empresa_id;
        $filtros = $this->filtros();
        $lotes = ReporteVencimientos::consulta($empresaId, $filtros)->paginate(30);
        $regularizando = null;

        if ($this->regProductoId !== null && $this->regSucursalId !== null) {
            $producto = Producto::query()->with('unidad:id,abreviatura')->find($this->regProductoId);
            $regularizando = [
                'producto' => $producto === null ? '' : Producto::formatoNombreCompleto($producto->nombre, $producto->contenido, $producto->unidad->abreviatura),
                'sucursal' => (string) Sucursal::query()->whereKey($this->regSucursalId)->value('nombre'),
                'disponible' => $this->saldoSinFecha(),
                'vencidos' => array_values(array_filter(array_map(function (array $linea): ?string {
                    $vence = DatosLote::vencimiento($linea['vencimiento']);

                    return DatosLote::vencido($vence) ? trim($linea['lote']).' · '.DatosLote::formatear($vence) : null;
                }, $this->regLineas))),
            ];
        }

        return view('livewire.inventario.vencimientos', [
            'lotes' => $lotes,
            'resumen' => ReporteVencimientos::resumen($empresaId, $filtros['sucursal_id']),
            'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre']),
            'filtrosActivos' => $filtros,
            'regularizando' => $regularizando,
        ]);
    }

    /**
     * Saldo actual del lote de regularización en la sucursal elegida (solo informa; la acción lo valida bloqueado).
     */
    private function saldoSinFecha(): string
    {
        return (string) (DB::table('stock_lotes as sl')
            ->join('lotes as l', 'l.id', '=', 'sl.lote_id')
            ->where('sl.empresa_id', (int) $this->actor()->empresa_id)
            ->where('l.empresa_id', (int) $this->actor()->empresa_id)
            ->where('sl.sucursal_id', $this->regSucursalId)
            ->where('sl.producto_id', $this->regProductoId)
            ->where('l.es_regularizacion', true)
            ->value('sl.cantidad') ?? '0');
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
