<?php

namespace App\Livewire\Creditos;

use App\Domain\Creditos\Actions\RegistrarCuentaCobrar;
use App\Domain\Creditos\Consultas\CuentasPorCobrar;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Cliente;
use App\Models\CuentaCobrar;
use App\Models\Pedido;
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
use Livewire\WithPagination;

/**
 * Cuentas por cobrar (§ 6): ventas a crédito y cuentas manuales en una sola lista, con lo
 * pendiente, lo vencido y lo por vencer. Todo usuario de la empresa la ve para poder cobrar
 * en su sucursal (decisión del propietario, 23/10/2026); solo el administrador registra
 * cuentas manuales.
 */
#[Title('Cuentas por cobrar')]
class Lista extends Component
{
    use MuestraAvisos, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    #[Url(except: 'todas')]
    public string $estado = 'todas';

    #[Url(except: '')]
    public string $sucursal = '';

    #[Url(except: '')]
    public string $desde = '';

    #[Url(except: '')]
    public string $hasta = '';

    public bool $formCuenta = false;

    #[Locked]
    public string $clave = '';

    public ?string $cliente_id = null;

    public ?string $sucursal_id = null;

    public string $concepto = '';

    public string $fecha = '';

    public string $vencimiento = '';

    public string $importe = '';

    public string $nota = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Pedido::class);

        if (! in_array($this->estado, CuentasPorCobrar::ESTADOS, true)) {
            $this->estado = 'todas';
        }

        $activa = $this->actor()->esAdministrador() ? $this->actor()->sucursalActivaId() : null;

        if ($activa !== null && $this->sucursal === '') {
            $this->sucursal = (string) $activa;
        }
    }

    public function updated(string $propiedad): void
    {
        if (in_array($propiedad, ['buscar', 'estado', 'sucursal', 'desde', 'hasta'], true)) {
            $this->resetPage();
        }
    }

    public function nuevaCuenta(): void
    {
        $this->authorize('create', CuentaCobrar::class);
        $this->resetValidation();
        $this->reset('cliente_id', 'concepto', 'vencimiento', 'importe', 'nota');
        $this->clave = (string) Str::uuid();
        $this->fecha = Carbon::now(config('mozistock.business_timezone'))->format('Y-m-d');
        $primera = $this->actor()->sucursalActivaId() ?? Sucursal::query()->where('activa', true)->orderBy('nombre')->value('id');
        $this->sucursal_id = $primera === null ? null : (string) $primera;
        $this->formCuenta = true;
    }

    public function registrarCuenta(RegistrarCuentaCobrar $accion): void
    {
        $this->resetValidation();
        $cuenta = $accion->ejecutar($this->actor(), [
            'cliente_id' => blank($this->cliente_id) ? null : (int) $this->cliente_id,
            'sucursal_id' => blank($this->sucursal_id) ? null : (int) $this->sucursal_id,
            'concepto' => $this->concepto,
            'fecha' => $this->fecha,
            'vencimiento' => $this->vencimiento === '' ? null : $this->vencimiento,
            'importe' => $this->importe,
            'nota' => $this->nota,
            'clave' => $this->clave,
        ]);

        session()->flash('aviso', __(':codigo registrada. No es una venta ni un ingreso de dinero.', ['codigo' => $cuenta->codigo()]));
        $this->redirectRoute('creditos.cuenta', $cuenta, navigate: false);
    }

    public function render(): View
    {
        $actor = $this->actor();
        $empresaId = (int) $actor->empresa_id;
        $sucursalId = ctype_digit($this->sucursal) ? (int) $this->sucursal : null;
        $fecha = fn (string $valor) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) ? $valor : null;

        return view('livewire.creditos.lista', [
            'cuentas' => CuentasPorCobrar::consulta($empresaId, $this->estado, $sucursalId, $this->buscar, $fecha($this->desde), $fecha($this->hasta))->paginate(25),
            'resumen' => CuentasPorCobrar::resumen($empresaId, $sucursalId),
            'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre', 'activa']),
            'clientes' => $this->formCuenta ? Cliente::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'documento']) : collect(),
            'administra' => $actor->esAdministrador(),
        ]);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
