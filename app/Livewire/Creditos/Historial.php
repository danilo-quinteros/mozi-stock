<?php

namespace App\Livewire\Creditos;

use App\Domain\Creditos\Actions\RegistrarAbono;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Historial de pagos de un crédito y registro de abonos en la misma pantalla (V3 § 6).
 * Lo que se muestra es solo informativo: el saldo y el importe se validan en RegistrarAbono.
 */
class Historial extends Component
{
    use MuestraAvisos;

    #[Locked]
    public int $ventaId;

    #[Locked]
    public string $clave = '';

    public string $metodo = 'efectivo';

    public string $monto = '';

    public string $efectivo = '';

    public string $electronico = '';

    public string $nota = '';

    public ?string $sucursal_id = null;

    public function mount(Venta $venta): void
    {
        $this->authorize('cobrar', $venta);
        $this->ventaId = $venta->id;
        $this->clave = (string) Str::uuid();
        $actor = $this->actor();
        $this->sucursal_id = (string) ($actor->sucursalActivaId() ?? $venta->sucursal_id);
    }

    public function registrar(RegistrarAbono $accion): void
    {
        $this->resetValidation();
        $pago = $accion->ejecutar($this->actor(), Venta::query()->findOrFail($this->ventaId), [
            'sucursal_id' => $this->sucursal_id === null ? null : (int) $this->sucursal_id,
            'metodo' => $this->metodo,
            'monto' => $this->monto,
            'efectivo' => $this->efectivo,
            'electronico' => $this->electronico,
            'nota' => $this->nota,
            'clave' => $this->clave,
        ]);

        $this->reset('monto', 'efectivo', 'electronico', 'nota');
        $this->clave = (string) Str::uuid();
        $this->avisar(__('Abono de :importe registrado.', ['importe' => Decimal::bs($pago->monto)]));
    }

    public function render(): View
    {
        $actor = $this->actor();
        $venta = Venta::query()
            ->with(['cliente:id,nombre,documento', 'sucursal:id,nombre', 'pedido:id,numero', 'vendedor:id,name',
                'pagos' => fn ($q) => $q->with(['sucursal:id,nombre', 'receptor:id,name'])])
            ->findOrFail($this->ventaId);
        $abonoMixto = Decimal::sumar(
            Decimal::normalizar($this->efectivo === '' ? '0' : $this->efectivo, 2) ?? '0',
            Decimal::normalizar($this->electronico === '' ? '0' : $this->electronico, 2) ?? '0',
            Decimal::ESCALA_DINERO,
        );

        return view('livewire.creditos.historial', [
            'venta' => $venta,
            'cobrado' => $venta->cobrado(),
            'pendiente' => Decimal::comparar($venta->saldo_pendiente, '0', 2) > 0,
            'abonoMixto' => $abonoMixto,
            'administra' => $actor->esAdministrador(),
            'sucursales' => $actor->esAdministrador()
                ? Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre'])
                : Sucursal::query()->whereKey($actor->sucursal_id)->get(['id', 'nombre']),
        ])->title(__('Historial de pagos · :codigo', ['codigo' => $venta->codigo()]));
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
