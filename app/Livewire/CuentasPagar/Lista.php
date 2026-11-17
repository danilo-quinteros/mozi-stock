<?php

namespace App\Livewire\CuentasPagar;

use App\Domain\CuentasPagar\Actions\RegistrarObligacion;
use App\Domain\CuentasPagar\Consultas\CuentasPorPagar;
use App\Livewire\Concerns\CreaProveedorRapido;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\CuentaPagar;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Cuentas por pagar (§ 15): obligaciones de compras, egresos y manuales con lo pagado, el
 * saldo y el vencimiento. Solo administradores.
 *
 * «Nueva obligación» (§ 14) se registra en un modal, igual que «Nueva cuenta por cobrar»: deuda que no nace de una
 * compra ni de un egreso registrados en MoziStock (saldo anterior, préstamo, impuesto). No mueve stock ni dinero; lo
 * pagado antes solo reduce el saldo inicial. Al registrarla se abre su detalle para pagarla.
 */
#[Title('Cuentas por pagar')]
class Lista extends Component
{
    use CreaProveedorRapido, MuestraAvisos, WithFileUploads, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    #[Url(except: 'todas')]
    public string $estado = 'todas';

    #[Url(except: '')]
    public string $sucursal = '';

    #[Url(except: '')]
    public string $proveedor = '';

    #[Url(except: '')]
    public string $origen = '';

    #[Url(except: '')]
    public string $desde = '';

    #[Url(except: '')]
    public string $hasta = '';

    public bool $formObligacion = false;

    /** Clave de idempotencia del modal: un doble envío no registra dos obligaciones. */
    #[Locked]
    public string $clave = '';

    public ?string $proveedor_id = null;

    public ?string $sucursal_id = null;

    public string $concepto = '';

    public string $fecha = '';

    public string $vencimiento = '';

    public string $importe = '';

    public string $pagado = '';

    public string $nota = '';

    /** @var TemporaryUploadedFile|null */
    public $respaldo = null;

    public function mount(): void
    {
        $this->authorize('viewAny', CuentaPagar::class);

        if (! in_array($this->estado, CuentasPorPagar::ESTADOS, true)) {
            $this->estado = 'todas';
        }

        $activa = $this->actor()->sucursalActivaId();

        if ($activa !== null && $this->sucursal === '') {
            $this->sucursal = (string) $activa;
        }

        if (request()->boolean('nueva') && $this->actor()->can('create', CuentaPagar::class)) {
            $this->nuevaObligacion();
        }
    }

    public function updated(string $propiedad): void
    {
        if (in_array($propiedad, ['buscar', 'estado', 'sucursal', 'proveedor', 'origen', 'desde', 'hasta'], true)) {
            $this->resetPage();
        }
    }

    public function nuevaObligacion(): void
    {
        $this->authorize('create', CuentaPagar::class);
        $this->resetValidation();
        $this->reset('proveedor_id', 'concepto', 'vencimiento', 'importe', 'pagado', 'nota', 'respaldo');
        $this->resetProveedorRapido();
        $this->clave = (string) Str::uuid();
        $this->fecha = Carbon::now(config('mozistock.business_timezone'))->format('Y-m-d');
        $primera = $this->actor()->sucursalActivaId() ?? Sucursal::query()->where('activa', true)->orderBy('nombre')->value('id');
        $this->sucursal_id = $primera === null ? null : (string) $primera;
        $this->formObligacion = true;
    }

    public function cerrarObligacion(): void
    {
        $this->formObligacion = false;
        $this->resetProveedorRapido();
        $this->resetValidation();
    }

    public function registrarObligacion(RegistrarObligacion $accion): void
    {
        if (! $this->formObligacion) {
            return;
        }

        $this->resetValidation();
        $cuenta = $accion->ejecutar($this->actor(), [
            'proveedor_id' => blank($this->proveedor_id) ? null : (int) $this->proveedor_id,
            'sucursal_id' => blank($this->sucursal_id) ? null : (int) $this->sucursal_id,
            'concepto' => $this->concepto,
            'fecha' => $this->fecha,
            'vencimiento' => $this->vencimiento === '' ? null : $this->vencimiento,
            'importe' => $this->importe,
            'pagado' => $this->pagado === '' ? '0' : $this->pagado,
            'nota' => $this->nota,
            'respaldo' => $this->respaldo instanceof TemporaryUploadedFile ? $this->respaldo : null,
            'clave' => $this->clave,
        ]);

        $this->formObligacion = false;
        session()->flash('aviso', __(':codigo registrada. No movió stock ni dinero.', ['codigo' => $cuenta->codigo()]));
        $this->redirectRoute('cuentas-pagar.ver', $cuenta, navigate: false);
    }

    public function render(): View
    {
        $empresaId = (int) $this->actor()->empresa_id;
        $sucursalId = ctype_digit($this->sucursal) ? (int) $this->sucursal : null;
        $fecha = fn (string $valor) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) ? $valor : null;

        return view('livewire.cuentas-pagar.lista', [
            'cuentas' => CuentasPorPagar::consulta($empresaId, $this->estado, $sucursalId, ctype_digit($this->proveedor) ? (int) $this->proveedor : null,
                $this->origen, $this->buscar, $fecha($this->desde), $fecha($this->hasta))->paginate(25),
            'resumen' => CuentasPorPagar::resumen($empresaId, $sucursalId),
            'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre']),
            'proveedores' => Proveedor::query()->orderBy('nombre')->get(['id', 'nombre', 'activo']),
            'sucursalesActivas' => $this->formObligacion ? Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre']) : collect(),
        ]);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
