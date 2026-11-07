<?php

namespace App\Livewire\CuentasPagar;

use App\Domain\CuentasPagar\Actions\RegistrarPagoCuenta;
use App\Livewire\Concerns\FormularioPago;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Auditoria;
use App\Models\CuentaPagar;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Cuenta por pagar (§ 16 y § 17): origen, pagos realizados y registro de un pago posterior en
 * la misma pantalla. El pago reduce solo el saldo de esta cuenta y sale de caja una sola vez;
 * nunca crea otro egreso ni otra compra. El administrador anula pagos u obligaciones manuales con
 * el modal compartido (Cuentas\Anular). El historial de cambios sale de la auditoría de la cuenta.
 */
class Detalle extends Component
{
    use FormularioPago, MuestraAvisos;

    #[Locked]
    public int $cuentaId;

    public ?string $sucursal_id = null;

    public function mount(CuentaPagar $cuenta): void
    {
        $this->authorize('view', $cuenta);
        $this->cuentaId = $cuenta->id;
        $this->sucursal_id = (string) ($this->actor()->sucursalActivaId() ?? $cuenta->sucursal_id);
        $this->iniciarPago();

        if (is_string($mensaje = session('aviso'))) {
            $this->avisar($mensaje);
        }
    }

    public function registrar(RegistrarPagoCuenta $accion): void
    {
        $this->resetValidation();
        $pago = $accion->ejecutar($this->actor(), CuentaPagar::query()->findOrFail($this->cuentaId), $this->datosPago() + [
            'sucursal_id' => blank($this->sucursal_id) ? null : (int) $this->sucursal_id,
        ]);

        $this->iniciarPago();
        $this->avisar(__('Pago de :importe registrado.', ['importe' => Decimal::bs($pago->monto)]));
    }

    public function render(): View
    {
        $cuenta = CuentaPagar::query()->with([
            'proveedor:id,nombre', 'sucursal:id,nombre', 'usuario:id,name', 'compra:id,numero', 'egreso:id,numero', 'anuladoPor:id,name',
            'pagos' => fn ($q) => $q->with(['sucursal:id,nombre', 'pagador:id,name', 'anuladoPor:id,name'])->orderBy('fecha_pago')->orderBy('id'),
        ])->findOrFail($this->cuentaId);

        return view('livewire.cuentas-pagar.detalle', [
            'cuenta' => $cuenta,
            'estado' => $cuenta->estado(),
            'pendiente' => ! $cuenta->estaAnulado() && Decimal::comparar($cuenta->saldo_pendiente, '0', 2) > 0,
            'sumaMixta' => $this->sumaMixta(),
            'administra' => true,
            'sucursales' => Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre']),
            'cambios' => $this->cambios($cuenta),
        ])->title(__('Cuenta por pagar · :codigo', ['codigo' => $cuenta->codigo()]));
    }

    /**
     * Historial de cambios relevantes (§ 17): alta, pagos y anulaciones registrados en auditoría.
     *
     * @return list<array{fecha: Carbon, usuario: string, accion: string, detalle: string}>
     */
    private function cambios(CuentaPagar $cuenta): array
    {
        $bs = fn (mixed $valor): string => is_numeric($valor) ? Decimal::bs((string) $valor) : '—';

        return array_values(Auditoria::query()->with('usuario:id,name')
            ->where('empresa_id', $cuenta->empresa_id)
            ->where('entidad', 'CuentaPagar')
            ->where('entidad_id', $cuenta->id)
            ->orderBy('id')
            ->get()
            ->map(function (Auditoria $registro) use ($bs): array {
                $antes = $registro->antes ?? [];
                $despues = $registro->despues ?? [];
                $saldo = $bs($despues['saldo_pendiente'] ?? null);
                $cambioSaldo = $bs($antes['saldo_pendiente'] ?? null).' → '.$saldo;
                $documento = ['total' => $bs($despues['total'] ?? $despues['importe'] ?? null), 'pagado' => $bs($despues['pagado'] ?? null), 'saldo' => $bs($despues['saldo'] ?? null)];

                return ['fecha' => $registro->created_at, 'usuario' => $registro->usuario->name ?? '—'] + match ($registro->accion) {
                    'cuenta_pagar.generada' => ['accion' => __('Generada por :documento', ['documento' => $despues['documento'] ?? '']),
                        'detalle' => __('Total :total · pagado :pagado · saldo :saldo', $documento)],
                    'cuenta_pagar.manual' => ['accion' => __('Obligación registrada'), 'detalle' => __('Importe :total · pagado antes :pagado · saldo :saldo', $documento)],
                    'cuenta_pagar.pago' => ['accion' => __('Pago registrado'), 'detalle' => $bs($despues['pago'] ?? null).' · '.$cambioSaldo],
                    'cuenta_pagar.pago_anulado' => ['accion' => __('Pago anulado'), 'detalle' => $bs($despues['monto'] ?? null).' · '.$cambioSaldo.' · '.($despues['motivo'] ?? '')],
                    'cuenta_pagar.anulada' => ['accion' => __('Obligación anulada'), 'detalle' => (string) ($despues['motivo'] ?? '')],
                    default => ['accion' => $registro->accion, 'detalle' => ''],
                };
            })
            ->all());
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
