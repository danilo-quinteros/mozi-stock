<?php

namespace App\Livewire\Inventario;

use App\Domain\Inventario\Actions\RegistrarAjuste;
use App\Enums\TipoOperacionInventario;
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

#[Title('Nuevo ajuste')]
class NuevoAjuste extends Component
{
    use CapturaProductos;

    public string $tipo = 'ajuste';

    public string $motivo = '';

    public string $direccion = 'sumar';

    public ?string $sucursal_id = null;

    public string $nota = '';

    public bool $confirmando = false;

    /**
     * Clave de idempotencia de esta pantalla: un doble envío no registra dos veces.
     */
    #[Locked]
    public string $clave = '';

    public function mount(): void
    {
        $this->authorize('create', OperacionInventario::class);
        $this->clave = (string) Str::uuid();
        $primera = $this->actor()->sucursalActivaId() ?? Sucursal::query()->where('activa', true)->orderBy('nombre')->value('id');
        $this->sucursal_id = $primera === null ? null : (string) $primera;
    }

    public function updatedTipo(): void
    {
        $this->motivo = '';
        $this->resetValidation(['motivo', 'direccion']);
    }

    public function revisar(): void
    {
        $this->resetValidation();
        $this->validate([
            'tipo' => ['required', 'in:entrada,salida,ajuste'],
            'motivo' => [$this->tipo === 'ajuste' ? 'nullable' : 'required'],
            'direccion' => [$this->tipo === 'ajuste' ? 'required' : 'nullable', 'in:sumar,restar'],
            'sucursal_id' => ['required'],
            'nota' => ['required', 'string', 'max:250'],
            'lineas' => ['required', 'array', 'min:1'],
        ], [
            'lineas.required' => __('Agrega al menos un producto.'),
            'lineas.min' => __('Agrega al menos un producto.'),
        ], ['nota' => __('nota'), 'sucursal_id' => __('sucursal')]);

        $this->confirmando = true;
    }

    public function confirmar(RegistrarAjuste $accion): void
    {
        $this->confirmando = false;
        $operacion = $accion->ejecutar($this->actor(), [
            'tipo' => $this->tipo,
            'motivo' => $this->motivo === '' ? null : $this->motivo,
            'direccion' => $this->direccion,
            'sucursal_id' => $this->sucursal_id === null ? null : (int) $this->sucursal_id,
            'nota' => $this->nota,
            'lineas' => $this->lineasParaAccion(),
            'clave' => $this->clave,
        ]);

        session()->flash('aviso', trans_choice(':codigo registrado: :count producto actualizado.|:codigo registrado: :count productos actualizados.', $operacion->total_lineas, ['codigo' => $operacion->codigo()]));
        $this->redirectRoute('inventario.movimientos', navigate: false);
    }

    public function render(): View
    {
        $tipo = TipoOperacionInventario::tryFrom($this->tipo) ?? TipoOperacionInventario::Ajuste;
        $sucursalId = $this->sucursal_id === null ? null : (int) $this->sucursal_id;
        $datos = $this->datosDeLineas($sucursalId);
        $signo = $tipo === TipoOperacionInventario::Salida || ($tipo === TipoOperacionInventario::Ajuste && $this->direccion === 'restar') ? '-1' : '1';
        $filas = [];
        $unidades = '0';

        foreach ($this->lineas as $indice => $linea) {
            $producto = $datos['productos']->get($linea['id']);

            if ($producto === null) {
                continue;
            }

            $actual = $datos['stock'][$linea['id']] ?? '0';
            $cantidad = Decimal::normalizar($linea['cantidad'], Decimal::ESCALA_CANTIDAD);
            $despues = $cantidad === null ? null : Decimal::sumar($actual, Decimal::multiplicar($cantidad, $signo));
            $unidades = $cantidad === null ? $unidades : Decimal::sumar($unidades, $cantidad);
            $filas[] = compact('indice', 'producto', 'actual', 'cantidad', 'despues');
        }

        return view('livewire.inventario.nuevo-ajuste', [
            'sucursales' => Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre']),
            'motivos' => $tipo->motivos(),
            'filas' => $filas,
            'unidades' => $unidades,
            'variacion' => Decimal::multiplicar($unidades, $signo),
            'tipoActual' => $tipo,
        ]);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
