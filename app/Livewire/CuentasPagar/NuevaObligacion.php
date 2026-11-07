<?php

namespace App\Livewire\CuentasPagar;

use App\Domain\CuentasPagar\Actions\RegistrarObligacion;
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
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Obligación manual (§ 14): deuda que no nace de una compra ni de un egreso registrados en
 * MoziStock (saldo anterior, préstamo, impuesto). No mueve stock ni dinero; lo pagado antes
 * solo reduce el saldo inicial.
 */
#[Title('Nueva obligación')]
class NuevaObligacion extends Component
{
    use WithFileUploads;

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
        $this->authorize('create', CuentaPagar::class);
        $this->clave = (string) Str::uuid();
        $this->fecha = Carbon::now(config('mozistock.business_timezone'))->format('Y-m-d');
        $primera = $this->actor()->sucursalActivaId() ?? Sucursal::query()->where('activa', true)->orderBy('nombre')->value('id');
        $this->sucursal_id = $primera === null ? null : (string) $primera;
    }

    public function guardar(RegistrarObligacion $accion): void
    {
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

        session()->flash('aviso', __(':codigo registrada. No movió stock ni dinero.', ['codigo' => $cuenta->codigo()]));
        $this->redirectRoute('cuentas-pagar.ver', $cuenta, navigate: false);
    }

    public function render(): View
    {
        return view('livewire.cuentas-pagar.nueva-obligacion', [
            'proveedores' => Proveedor::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'sucursales' => Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
