<?php

namespace App\Livewire\Cuentas;

use App\Domain\Creditos\Actions\AnularCobro;
use App\Domain\Creditos\Actions\AnularCuentaCobrar;
use App\Domain\CuentasPagar\Actions\AnularObligacion;
use App\Domain\CuentasPagar\Actions\AnularPagoCuenta;
use App\Livewire\Concerns\EligeCaja;
use App\Models\Cobro;
use App\Models\CobroAplicacion;
use App\Models\CuentaCobrar;
use App\Models\CuentaPagar;
use App\Models\PagoCuentaPagar;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Modal compartido para que el administrador anule (31/10/2026) un cobro, un pago de cuenta por
 * pagar, una cuenta por cobrar manual o una obligación manual. Las páginas lo abren con el evento
 * «anular» ({tipo, id}). Si el registro movió efectivo, se elige la caja de la contrapartida
 * (por defecto su mismo turno si sigue abierto) o «externo». Las acciones validan y bloquean todo.
 */
class Anular extends Component
{
    use EligeCaja;

    private const TIPOS = ['cobro', 'pago', 'cuenta', 'obligacion'];

    /** Página a la que se vuelve tras anular. */
    #[Locked]
    public string $volver = '';

    #[Locked]
    public string $tipo = '';

    #[Locked]
    public int $registroId = 0;

    /** Sucursal del dinero original: la contrapartida se registra en ella. */
    #[Locked]
    public ?string $sucursal_id = null;

    public string $motivo = '';

    public bool $abierto = false;

    public function mount(string $volver): void
    {
        $this->volver = $volver;
    }

    #[On('anular')]
    public function abrir(string $tipo, int $id): void
    {
        abort_unless(in_array($tipo, self::TIPOS, true), 404);
        $registro = $this->registro($tipo, $id);
        $this->tipo = $tipo;
        $this->registroId = $registro->id;
        $this->reset('motivo');
        $this->resetValidation();
        unset($this->resumen, $this->opcionesCaja);

        if ($registro instanceof Cobro || $registro instanceof PagoCuentaPagar) {
            $this->sucursal_id = (string) $registro->sucursal_id;
            $this->elegirCajaPorDefecto();

            if ($registro->turno_caja_id !== null && array_key_exists($registro->turno_caja_id, $this->opcionesCaja())) {
                $this->caja = (string) $registro->turno_caja_id;
            }
        } else {
            $this->sucursal_id = null;
            $this->caja = '';
        }

        $this->abierto = true;
    }

    public function confirmar(AnularCobro $cobros, AnularPagoCuenta $pagos, AnularCuentaCobrar $cuentas, AnularObligacion $obligaciones): void
    {
        $this->resetValidation();
        $actor = $this->actor();
        $registro = $this->registro($this->tipo, $this->registroId);
        $dinero = ['motivo' => $this->motivo] + $this->datosCaja();

        $mensaje = match (true) {
            $registro instanceof Cobro => __('Cobro :codigo anulado.', ['codigo' => $cobros->ejecutar($actor, $registro, $dinero)->codigo()]),
            $registro instanceof PagoCuentaPagar => __('Pago de :importe anulado.', ['importe' => Decimal::bs($pagos->ejecutar($actor, $registro, $dinero)->monto)]),
            $registro instanceof CuentaCobrar => __('Cuenta :codigo anulada.', ['codigo' => $cuentas->ejecutar($actor, $registro, ['motivo' => $this->motivo])->codigo()]),
            default => __('Obligación :codigo anulada.', ['codigo' => $obligaciones->ejecutar($actor, $registro, ['motivo' => $this->motivo])->codigo()]),
        };

        session()->flash('aviso', $mensaje);
        $this->redirect($this->volver);
    }

    /**
     * Qué se anula y qué pasará, para el modal.
     *
     * @return array{titulo: string, texto: string, efectivo: bool}
     */
    #[Computed]
    public function resumen(): array
    {
        $registro = $this->registro($this->tipo, $this->registroId);
        $zona = config('mozistock.business_timezone');

        if ($registro instanceof Cobro) {
            $documentos = CobroAplicacion::query()->with(['venta:id,numero', 'cuenta:id,numero'])->where('cobro_id', $registro->id)->get()
                ->map(fn (CobroAplicacion $a) => $a->venta?->codigo() ?? $a->cuenta?->codigo())->unique()->implode(', ');

            return [
                'titulo' => __('Anular cobro :codigo', ['codigo' => $registro->codigo()]),
                'texto' => __(':importe (:metodo) recibido el :fecha y aplicado a :documentos. Esos saldos vuelven a quedar pendientes y hoy se registra la salida de :importe. El cobro queda en el historial como anulado.', [
                    'importe' => Decimal::bs($registro->monto), 'metodo' => $registro->metodo->etiqueta(),
                    'fecha' => $registro->fecha_cobro->timezone($zona)->format('d/m/Y H:i'), 'documentos' => $documentos,
                ]),
                'efectivo' => Decimal::comparar($registro->monto_efectivo, '0', 2) > 0,
            ];
        }

        if ($registro instanceof PagoCuentaPagar) {
            return [
                'titulo' => __('Anular pago de :codigo', ['codigo' => $registro->cuenta->codigo()]),
                'texto' => __(':importe (:metodo) pagado el :fecha. El saldo de la cuenta vuelve a subir y hoy se registra la entrada (devolución) de :importe. El pago queda en el historial como anulado.', [
                    'importe' => Decimal::bs($registro->monto), 'metodo' => $registro->metodo->etiqueta(),
                    'fecha' => $registro->fecha_pago->timezone($zona)->format('d/m/Y H:i'),
                ]),
                'efectivo' => Decimal::comparar($registro->monto_efectivo, '0', 2) > 0,
            ];
        }

        return [
            'titulo' => $registro instanceof CuentaCobrar
                ? __('Anular cuenta :codigo', ['codigo' => $registro->codigo()])
                : __('Anular obligación :codigo', ['codigo' => $registro->codigo()]),
            'texto' => __('Deja de contar en saldos y vencidos. No mueve dinero. Queda en el historial como anulada; si tiene cobros o pagos vigentes, anúlalos primero.'),
            'efectivo' => false,
        ];
    }

    public function render(): View
    {
        return view('livewire.cuentas.anular');
    }

    /**
     * El registro, de la empresa del usuario (el scope responde 404 para otra) y autorizado.
     */
    private function registro(string $tipo, int $id): Cobro|PagoCuentaPagar|CuentaCobrar|CuentaPagar
    {
        $registro = match ($tipo) {
            'cobro' => Cobro::query()->findOrFail($id),
            'pago' => PagoCuentaPagar::query()->findOrFail($id),
            'cuenta' => CuentaCobrar::query()->findOrFail($id),
            default => CuentaPagar::query()->findOrFail($id),
        };
        $this->authorize('anular', $registro instanceof PagoCuentaPagar ? $registro->cuenta : $registro);

        return $registro;
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
