<?php

namespace App\Livewire\Creditos;

use App\Domain\Creditos\Actions\DefinirCondicionesCredito;
use App\Domain\Creditos\Actions\RegistrarCobro;
use App\Domain\Creditos\Consultas\HistorialPagos;
use App\Domain\Creditos\Consultas\PendientesCobro;
use App\Domain\Creditos\PlanCuotas;
use App\Livewire\Concerns\FormularioPago;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\CobroAplicacion;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Cuenta por cobrar de una venta a crédito (Cuentas por cobrar § 7 y § 8): condiciones
 * (sin vencimiento, fecha única o plan de cuotas), historial único de pagos (cuota inicial,
 * abonos anteriores y cobros) y registro del cobro en la misma pantalla, automático o por cuota.
 * Lo que se muestra es informativo: RegistrarCobro y DefinirCondicionesCredito validan todo.
 */
class Historial extends Component
{
    use FormularioPago, MuestraAvisos;

    #[Locked]
    public int $ventaId;

    public ?string $sucursal_id = null;

    public string $modo = 'automatico';

    /** @var array<string, string> */
    public array $asignaciones = [];

    public bool $formCondiciones = false;

    public string $condicion = 'ninguno';

    public string $vencimiento = '';

    public string $planCantidad = '3';

    public string $planPrimera = '';

    public string $planFrecuencia = 'mensual';

    /**
     * Filas editables del plan; llegan del navegador y DefinirCondicionesCredito las valida.
     *
     * @var array<int, array<string, string>>
     */
    public array $plan = [];

    public function mount(Venta $venta): void
    {
        $this->authorize('cobrar', $venta);
        $this->ventaId = $venta->id;
        $actor = $this->actor();
        $this->sucursal_id = (string) ($actor->esVendedor() ? $actor->sucursal_id : ($actor->sucursalActivaId() ?? $venta->sucursal_id));
        $this->iniciarPago();

        if (is_string($mensaje = session('aviso'))) {
            $this->avisar($mensaje);
        }
    }

    public function registrar(RegistrarCobro $accion): void
    {
        $this->resetValidation();
        $cobro = $accion->ejecutar($this->actor(), $this->datosPago() + [
            'venta_id' => $this->ventaId,
            'sucursal_id' => blank($this->sucursal_id) ? null : (int) $this->sucursal_id,
            'modo' => $this->modo,
            'asignaciones' => $this->asignaciones,
        ]);

        $this->iniciarPago();
        $this->reset('asignaciones', 'modo');
        $this->avisar(__('Cobro :codigo de :importe registrado.', ['codigo' => $cobro->codigo(), 'importe' => Decimal::bs($cobro->monto)]));
    }

    public function editarCondiciones(): void
    {
        $venta = $this->venta();
        $this->authorize('condicionesCredito', $venta);
        $this->resetValidation();
        $this->condicion = $venta->cuotas->isNotEmpty() ? 'cuotas' : ($venta->vencimiento === null ? 'ninguno' : 'unico');
        $this->vencimiento = $venta->vencimiento?->format('Y-m-d') ?? '';
        $this->plan = $venta->cuotas->map(fn ($cuota) => ['vencimiento' => $cuota->vencimiento->format('Y-m-d'), 'monto' => $cuota->monto])->all();
        $this->planPrimera = Carbon::now(config('mozistock.business_timezone'))->addMonthNoOverflow()->format('Y-m-d');
        $this->formCondiciones = true;
    }

    public function generarPlan(): void
    {
        $this->resetValidation();
        $cantidad = ctype_digit($this->planCantidad) ? (int) $this->planCantidad : 0;

        if ($cantidad < 1 || $cantidad > PlanCuotas::MAX_CUOTAS || ! array_key_exists($this->planFrecuencia, PlanCuotas::FRECUENCIAS)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->planPrimera)) {
            $this->addError('cuotas', __('Indica entre 1 y :max cuotas, la primera fecha y la frecuencia.', ['max' => PlanCuotas::MAX_CUOTAS]));

            return;
        }

        $this->plan = PlanCuotas::generar($this->venta()->saldo_pendiente, $cantidad, $this->planPrimera, $this->planFrecuencia);
    }

    public function quitarCuota(int $indice): void
    {
        unset($this->plan[$indice]);
        $this->plan = array_values($this->plan);
    }

    public function agregarCuota(): void
    {
        $this->plan[] = ['vencimiento' => '', 'monto' => ''];
    }

    public function guardarCondiciones(DefinirCondicionesCredito $accion): void
    {
        $this->resetValidation();
        $accion->ejecutar($this->actor(), $this->venta(), [
            'tipo' => $this->condicion,
            'vencimiento' => $this->vencimiento === '' ? null : $this->vencimiento,
            'cuotas' => $this->plan,
        ]);

        $this->formCondiciones = false;
        $this->avisar(__('Condiciones del crédito guardadas.'));
    }

    public function render(): View
    {
        $actor = $this->actor();
        $venta = $this->venta()->load(['cliente:id,nombre,documento', 'sucursal:id,nombre', 'pedido:id,numero', 'vendedor:id,name']);
        $pendiente = Decimal::comparar($venta->saldo_pendiente, '0', 2) > 0;

        return view('livewire.creditos.historial', [
            'venta' => $venta,
            'cobrado' => $venta->cobrado(),
            'pendiente' => $pendiente,
            'movimientos' => HistorialPagos::de($venta),
            'items' => $pendiente ? PendientesCobro::cargar('venta', $venta->id)['items'] : [],
            'planEditable' => $venta->cuotas->every(fn ($cuota) => Decimal::comparar($cuota->saldo, $cuota->monto, 2) === 0)
                && ! CobroAplicacion::query()->whereIn('venta_cuota_id', $venta->cuotas->modelKeys())->exists(),
            'sumaPlan' => array_reduce($this->plan, fn (string $suma, array $fila) => Decimal::sumar($suma, Decimal::normalizar((string) ($fila['monto'] ?? ''), 2) ?? '0', 2), '0.00'),
            'sumaMixta' => $this->sumaMixta(),
            'administra' => $actor->esAdministrador(),
            'sucursales' => $actor->esAdministrador()
                ? Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre'])
                : Sucursal::query()->whereKey($actor->sucursal_id)->get(['id', 'nombre']),
        ])->title(__('Cuenta por cobrar · :codigo', ['codigo' => $venta->codigo()]));
    }

    private function venta(): Venta
    {
        return Venta::query()->with('cuotas')->findOrFail($this->ventaId);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
