<?php

namespace App\Livewire\Inventario;

use App\Domain\Inventario\Actions\RegistrarTraspaso;
use App\Livewire\Concerns\CapturaProductos;
use App\Models\OperacionInventario;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Nuevo traspaso')]
class NuevoTraspaso extends Component
{
    use CapturaProductos;

    public ?string $sucursal_id = null;

    public ?string $sucursal_destino_id = null;

    public string $nota = '';

    public bool $confirmando = false;

    #[Locked]
    public string $clave = '';

    public function mount(): void
    {
        $this->authorize('create', OperacionInventario::class);
        $this->clave = (string) Str::uuid();
        // La sucursal fijada en el menú lateral es el origen por defecto.
        $ids = Sucursal::query()->where('activa', true)->orderBy('nombre')->pluck('id')->all();
        $origen = $this->actor()->sucursalActivaId() ?? ($ids[0] ?? null);
        $destino = array_values(array_diff($ids, [$origen]))[0] ?? null;
        $this->sucursal_id = $origen === null ? null : (string) $origen;
        $this->sucursal_destino_id = $destino === null ? null : (string) $destino;
    }

    public function revisar(): void
    {
        $this->resetValidation();
        $this->validate([
            'sucursal_id' => ['required'],
            'sucursal_destino_id' => ['required', 'different:sucursal_id'],
            'nota' => ['nullable', 'string', 'max:250'],
            'lineas' => ['required', 'array', 'min:1'],
        ], [
            'sucursal_destino_id.different' => __('El destino debe ser distinto del origen.'),
            'lineas.required' => __('Agrega al menos un producto.'),
            'lineas.min' => __('Agrega al menos un producto.'),
        ], ['sucursal_id' => __('sucursal de origen'), 'sucursal_destino_id' => __('sucursal de destino')]);

        $this->confirmando = true;
    }

    public function confirmar(RegistrarTraspaso $accion): void
    {
        $this->confirmando = false;
        $operacion = $accion->ejecutar($this->actor(), [
            'sucursal_id' => $this->sucursal_id === null ? null : (int) $this->sucursal_id,
            'sucursal_destino_id' => $this->sucursal_destino_id === null ? null : (int) $this->sucursal_destino_id,
            'nota' => $this->nota,
            'lineas' => $this->lineasParaAccion(),
            'clave' => $this->clave,
        ]);

        session()->flash('aviso', trans_choice(':codigo registrado: :count producto trasladado.|:codigo registrado: :count productos trasladados.', $operacion->total_lineas, ['codigo' => $operacion->codigo()]));
        $this->redirectRoute('inventario.movimientos', navigate: false);
    }

    public function render(): View
    {
        $origenId = $this->sucursal_id === null ? null : (int) $this->sucursal_id;
        $datos = $this->datosDeLineas($origenId);
        $filas = [];
        $unidades = '0';

        foreach ($this->lineas as $indice => $linea) {
            $producto = $datos['productos']->get($linea['id']);

            if ($producto === null) {
                continue;
            }

            $actual = $datos['stock'][$linea['id']] ?? '0';
            $cantidad = Decimal::normalizar($linea['cantidad'], Decimal::ESCALA_CANTIDAD);
            $despues = $cantidad === null ? null : Decimal::restar($actual, $cantidad);
            $unidades = $cantidad === null ? $unidades : Decimal::sumar($unidades, $cantidad);
            $filas[] = compact('indice', 'producto', 'actual', 'cantidad', 'despues');
        }

        return view('livewire.inventario.nuevo-traspaso', [
            'sucursales' => Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre']),
            'filas' => $filas,
            'unidades' => $unidades,
        ]);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
