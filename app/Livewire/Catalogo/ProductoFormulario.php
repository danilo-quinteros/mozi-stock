<?php

namespace App\Livewire\Catalogo;

use App\Domain\Catalogo\Actions\ConfigurarMinimosSucursal;
use App\Domain\Catalogo\Actions\GuardarProducto;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\StockNivel;
use App\Models\Sucursal;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class ProductoFormulario extends Component
{
    use WithFileUploads;

    private const PRECIO = 'regex:/^\d{1,15}([.,]\d{1,2})?$/';

    private const CANTIDAD = 'regex:/^\d{1,15}([.,]\d{1,3})?$/';

    private const REGLAS_IMAGEN = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'];

    #[Locked]
    public ?int $productoId = null;

    public string $nombre = '';

    public string $sku = '';

    public string $codigo_barras = '';

    public string $categoria_id = '';

    public string $unidad_medida_id = '';

    public string $stock_minimo = '0';

    public string $precio_consumidor = '';

    public string $precio_minorista = '';

    public string $precio_mayorista = '';

    public string $descripcion = '';

    public bool $activo = true;

    /** @var TemporaryUploadedFile|null */
    public $imagen = null;

    public bool $quitarImagen = false;

    /**
     * @var array<int|string, string> sucursal_id => mínimo propio (vacío = general)
     */
    public array $minimos = [];

    public function mount(?Producto $producto = null): void
    {
        if ($producto === null || ! $producto->exists) {
            $this->authorize('create', Producto::class);
            $this->unidad_medida_id = (string) UnidadMedida::query()->where('activa', true)->orderBy('id')->value('id');

            return;
        }

        $this->authorize('update', $producto);
        $this->productoId = $producto->id;
        $this->nombre = $producto->nombre;
        $this->sku = $producto->sku;
        $this->codigo_barras = (string) $producto->codigo_barras;
        $this->categoria_id = (string) $producto->categoria_id;
        $this->unidad_medida_id = (string) $producto->unidad_medida_id;
        $this->stock_minimo = Decimal::simple($producto->stock_minimo);
        $this->precio_consumidor = $producto->precio_consumidor;
        $this->precio_minorista = (string) $producto->precio_minorista;
        $this->precio_mayorista = (string) $producto->precio_mayorista;
        $this->descripcion = (string) $producto->descripcion;
        $this->activo = $producto->activo;
        $this->minimos = StockNivel::query()->where('producto_id', $producto->id)->whereNotNull('stock_minimo')
            ->pluck('stock_minimo', 'sucursal_id')->map(fn ($minimo) => Decimal::simple((string) $minimo))->all();
    }

    /**
     * Valida la imagen apenas se sube, antes de intentar mostrar su vista previa.
     */
    public function updatedImagen(): void
    {
        try {
            $this->validate(['imagen' => self::REGLAS_IMAGEN], ['imagen.max' => __('La imagen no puede superar 3 MB.')]);
            $this->quitarImagen = false;
        } catch (ValidationException $e) {
            $this->imagen = null;

            throw $e;
        }
    }

    public function quitarFoto(): void
    {
        $this->imagen = null;
        $this->quitarImagen = true;
    }

    public function guardar(GuardarProducto $accion, ConfigurarMinimosSucursal $minimos): void
    {
        $empresaId = $this->actor()->empresa_id;
        $this->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'sku' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._\-\/]+$/',
                Rule::unique('productos', 'sku')->where('empresa_id', $empresaId)->ignore($this->productoId)],
            'codigo_barras' => ['nullable', 'string', 'max:64',
                Rule::unique('productos', 'codigo_barras')->where('empresa_id', $empresaId)->ignore($this->productoId)],
            'categoria_id' => ['nullable', Rule::exists('categorias', 'id')->where('empresa_id', $empresaId)],
            'unidad_medida_id' => ['required', Rule::exists('unidades_medida', 'id')->where('empresa_id', $empresaId)],
            'stock_minimo' => ['required', self::CANTIDAD],
            'precio_consumidor' => ['required', self::PRECIO],
            'precio_minorista' => ['nullable', self::PRECIO],
            'precio_mayorista' => ['nullable', self::PRECIO],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'imagen' => self::REGLAS_IMAGEN,
            'minimos.*' => ['nullable', self::CANTIDAD],
        ], [
            'sku.regex' => __('Usa letras, números, puntos, guiones o barras.'),
            'sku.unique' => __('Ya existe un producto con ese código en tu empresa.'),
            'codigo_barras.unique' => __('Ese código de barras ya está asignado a otro producto.'),
            'precio_consumidor.regex' => __('Ingresa un precio válido (por ejemplo 13,00).'),
            'precio_minorista.regex' => __('Ingresa un precio válido (por ejemplo 11,10).'),
            'precio_mayorista.regex' => __('Ingresa un precio válido (por ejemplo 9,80).'),
            'stock_minimo.regex' => __('Ingresa una cantidad mayor o igual a 0.'),
            'minimos.*.regex' => __('Ingresa una cantidad mayor o igual a 0, o déjalo vacío.'),
            'imagen.max' => __('La imagen no puede superar 3 MB.'),
        ], [
            'sku' => __('código / SKU'), 'codigo_barras' => __('código de barras'), 'categoria_id' => __('categoría'),
            'unidad_medida_id' => __('unidad'), 'stock_minimo' => __('stock mínimo'), 'precio_consumidor' => __('precio consumidor'),
            'precio_minorista' => __('precio minorista'), 'precio_mayorista' => __('precio mayorista'), 'descripcion' => __('descripción'),
        ]);

        $datos = [
            'nombre' => $this->nombre, 'sku' => $this->sku, 'codigo_barras' => $this->codigo_barras,
            'descripcion' => $this->descripcion, 'categoria_id' => $this->categoria_id,
            'unidad_medida_id' => $this->unidad_medida_id, 'stock_minimo' => $this->stock_minimo,
            'precio_consumidor' => $this->precio_consumidor, 'precio_minorista' => $this->precio_minorista,
            'precio_mayorista' => $this->precio_mayorista, 'activo' => $this->activo,
        ];

        if ($this->productoId === null) {
            $producto = $accion->crear($this->actor(), $datos, $this->imagen);
            session()->flash('aviso', __('Producto :sku creado. Carga su stock desde Ajustes y traspasos.', ['sku' => $producto->sku]));
        } else {
            $producto = $accion->actualizar($this->actor(), Producto::query()->findOrFail($this->productoId), $datos, $this->imagen, $this->quitarImagen);

            if ($this->minimos !== []) {
                $minimos->ejecutar($this->actor(), $producto, $this->minimos);
            }

            session()->flash('aviso', __('Producto :sku actualizado.', ['sku' => $producto->sku]));
        }

        $this->redirectRoute('productos.index', navigate: false);
    }

    public function render(): View
    {
        $producto = $this->productoId === null ? null : Producto::query()->find($this->productoId);
        $stock = $producto === null ? collect() : StockNivel::query()->where('producto_id', $producto->id)->pluck('cantidad', 'sucursal_id');

        return view('livewire.catalogo.producto-formulario', [
            'producto' => $producto,
            'categorias' => Categoria::query()->where(fn ($q) => $q->where('activa', true)->orWhere('id', (int) $this->categoria_id))->orderBy('nombre')->get(['id', 'nombre']),
            'unidades' => UnidadMedida::query()->where(fn ($q) => $q->where('activa', true)->orWhere('id', (int) $this->unidad_medida_id))->orderBy('nombre')->get(['id', 'nombre', 'abreviatura']),
            'stockSucursales' => $producto === null ? collect() : Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn (Sucursal $sucursal) => [
                    'sucursal' => $sucursal,
                    'stock' => (string) ($stock->get($sucursal->id) ?? '0'),
                ]),
        ])->title($this->productoId === null ? __('Nuevo producto') : __('Editar producto'));
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
