<?php

namespace App\Livewire\Caja;

use App\Domain\Reportes\Consultas\Cobros;
use App\Domain\Reportes\Consultas\Salidas;
use App\Domain\Reportes\Periodo;
use App\Livewire\Concerns\UsaSucursalActiva;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Arqueo automático por día y sucursal (decisión del propietario, 23/10/2026): se calcula
 * desde el libro de caja, sin apertura ni cierre. Dinero recibido ≠ valor vendido. Las
 * salidas (compras, egresos y reembolsos) se descuentan en el día en que se pagaron.
 */
#[Title('Caja y arqueo')]
class Arqueo extends Component
{
    use UsaSucursalActiva, WithPagination;

    #[Url(except: '')]
    public string $fecha = '';

    #[Url(except: '')]
    public string $sucursal = '';

    #[Url(except: '')]
    public string $metodo = '';

    public function mount(): void
    {
        $this->authorize('ver-reportes');
        $this->aplicarSucursalActiva();

        if ($this->fecha === '') {
            $this->fecha = Periodo::hoy()->desde->format('Y-m-d');
        }
    }

    public function updated(string $propiedad): void
    {
        $this->resetPage('cobros');
        $this->resetPage('salidas');
    }

    public function render(): View
    {
        /** @var User $actor */
        $actor = Auth::user();
        $empresaId = (int) $actor->empresa_id;
        $dia = Periodo::dia($this->fecha);
        $sucursalId = ctype_digit($this->sucursal) ? (int) $this->sucursal : null;
        $metodo = in_array($this->metodo, ['efectivo', 'electronico'], true) ? $this->metodo : '';
        $semana = Periodo::rango($dia->desde->subDays(6)->format('Y-m-d'), $dia->desde->format('Y-m-d'));
        $porDia = Cobros::porDia($empresaId, $semana, $sucursalId);
        $maximo = array_reduce($porDia, fn (string $max, string $v) => Decimal::comparar($v, $max, 2) > 0 ? $v : $max, '0');
        $barras = [];

        for ($d = $semana->desde; $d->lessThanOrEqualTo($semana->hasta); $d = $d->addDay()) {
            $total = $porDia[$d->format('Y-m-d')] ?? '0.00';
            $alto = Decimal::comparar($maximo, '0', 2) > 0 ? (int) Decimal::dividir(Decimal::multiplicar($total, '100', 2), $maximo, 0) : 0;
            $barras[] = ['etiqueta' => $d->format('d'), 'fecha' => $d->format('d/m'), 'total' => $total, 'alto' => $alto];
        }

        return view('livewire.caja.arqueo', [
            'dia' => $dia,
            'resumen' => Cobros::resumen($empresaId, $dia, $sucursalId, $metodo),
            'barras' => $barras,
            'porSucursal' => Cobros::porSucursal($empresaId, $dia),
            'cobros' => Cobros::detalle($empresaId, $dia, $sucursalId, $metodo)->paginate(25, pageName: 'cobros'),
            'salidas' => Salidas::detalle($empresaId, $dia, $sucursalId, $metodo)->paginate(25, pageName: 'salidas'),
            'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }
}
