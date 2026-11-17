<?php

namespace App\Livewire\Produccion;

use App\Domain\Inventario\LibroLotes;
use App\Domain\Produccion\Actions\GuardarMateriaPrima;
use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Formulario de materia prima (ESPECIFICACION_PRODUCCION.md § 4.1, Excel § 1.1): código, nombre, unidad, usos,
 * stock mínimo y máximo, observación, lote y vencimiento, y costo inicial mientras no tenga compras. Toda la regla
 * vive en GuardarMateriaPrima; el producto se resuelve con el scope de empresa y solo si es materia prima.
 */
#[Title('Materia prima')]
class MateriaPrimaFormulario extends Component
{
    #[Locked]
    public ?int $materiaId = null;

    public string $nombre = '';

    public string $sku = '';

    public string $unidad_medida_id = '';

    public string $usos = '';

    public string $observacion = '';

    public string $stock_minimo = '';

    public string $stock_maximo = '';

    public bool $controla_vencimiento = true;

    public string $codigo_barras = '';

    public string $costo_inicial = '';

    public bool $activo = true;

    public function mount(?Producto $producto = null): void
    {
        $this->authorize('usar-produccion');

        if ($producto === null || ! $producto->exists) {
            $this->unidad_medida_id = (string) UnidadMedida::query()->where('activa', true)->orderBy('id')->value('id');

            return;
        }

        abort_unless($producto->clase === Producto::CLASE_MATERIA_PRIMA, 404);
        $this->materiaId = $producto->id;
        $this->nombre = $producto->nombre_base;
        $this->sku = (string) ($producto->sku ?? '');
        $this->unidad_medida_id = (string) $producto->unidad_medida_id;
        $this->usos = (string) ($producto->descripcion ?? '');
        $this->observacion = (string) ($producto->observacion ?? '');
        $this->stock_minimo = Decimal::comparar((string) $producto->stock_minimo, '0') > 0 ? Decimal::simple((string) $producto->stock_minimo) : '';
        $this->stock_maximo = $producto->stock_maximo === null ? '' : Decimal::simple((string) $producto->stock_maximo);
        $this->controla_vencimiento = (bool) $producto->controla_vencimiento;
        $this->codigo_barras = (string) ($producto->codigo_barras ?? '');
        $this->costo_inicial = Decimal::comparar((string) $producto->costo_promedio, '0', 6) > 0 ? Decimal::simple((string) $producto->costo_promedio) : '';
        $this->activo = (bool) $producto->activo;
    }

    public function guardar(GuardarMateriaPrima $accion): void
    {
        $this->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'sku' => ['nullable', 'string', 'max:40'],
            'unidad_medida_id' => ['required'],
            'usos' => ['nullable', 'string', 'max:2000'],
            'observacion' => ['nullable', 'string', 'max:250'],
            'codigo_barras' => ['nullable', 'string', 'max:64'],
        ], [], [
            'nombre' => __('nombre'), 'sku' => __('código'), 'unidad_medida_id' => __('unidad'), 'usos' => __('usos y aplicaciones'),
            'observacion' => __('observación'), 'codigo_barras' => __('código de barras'),
        ]);

        $datos = [
            'nombre' => $this->nombre,
            'sku' => $this->sku,
            'unidad_medida_id' => $this->unidad_medida_id,
            'usos' => $this->usos,
            'observacion' => $this->observacion,
            'stock_minimo' => $this->stock_minimo,
            'stock_maximo' => $this->stock_maximo,
            'controla_vencimiento' => $this->controla_vencimiento,
            'codigo_barras' => $this->codigo_barras,
            // Con compras el costo lo calcula el sistema: no se envía (GuardarProducto lo rechazaría si cambiara).
            'costo_inicial' => $this->tieneCompras() ? '' : $this->costo_inicial,
        ];
        $actor = $this->actor();

        if ($this->materiaId === null) {
            $materia = $accion->crear($actor, $datos);
            session()->flash('aviso', __(':materia creada con el código :codigo.', ['materia' => $materia->nombre, 'codigo' => $materia->sku]));
        } else {
            // El scope de empresa descarta productos de otra empresa; la acción exige que sea materia prima.
            $materia = $accion->actualizar($actor, Producto::query()->findOrFail($this->materiaId), $datos + ['activo' => $this->activo]);
            session()->flash('aviso', __(':materia guardada.', ['materia' => $materia->nombre]));
        }

        $this->redirectRoute('produccion.materias', navigate: false);
    }

    public function render(): View
    {
        return view('livewire.produccion.materia-prima-formulario', [
            'unidades' => UnidadMedida::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre', 'abreviatura']),
            'conLotes' => app(LibroLotes::class)->activo((int) $this->actor()->empresa_id),
            // Producción § 4.1: el costo inicial solo se edita mientras no haya compras.
            'conCompras' => $this->tieneCompras(),
        ]);
    }

    private function tieneCompras(): bool
    {
        return $this->materiaId !== null && DB::table('compra_items')
            ->where('empresa_id', (int) $this->actor()->empresa_id)->where('producto_id', $this->materiaId)->exists();
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
