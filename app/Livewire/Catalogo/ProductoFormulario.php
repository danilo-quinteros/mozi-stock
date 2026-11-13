<?php

namespace App\Livewire\Catalogo;

use App\Domain\Catalogo\Actions\ConfigurarMinimosSucursal;
use App\Domain\Catalogo\Actions\GuardarModelo;
use App\Domain\Catalogo\Actions\GuardarProducto;
use App\Domain\Catalogo\Variantes;
use App\Domain\Inventario\Consultas\StockLotes;
use App\Domain\Inventario\StockInicial;
use App\Enums\Capacidad;
use App\Livewire\Concerns\ArmaCombinaciones;
use App\Models\Categoria;
use App\Models\Modelo;
use App\Models\Producto;
use App\Models\StockNivel;
use App\Models\Sucursal;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * @property-read string $vistaPreviaNombreCompleto
 * @property-read string $vistaPreviaNombreBase
 * @property-read string $vistaPreviaContenido
 * @property-read string $textoEjemplo
 */
class ProductoFormulario extends Component
{
    use ArmaCombinaciones, WithFileUploads;

    private const PRECIO = 'regex:/^\d{1,15}([.,]\d{1,2})?$/';

    private const CANTIDAD = 'regex:/^\d{1,15}([.,]\d{1,3})?$/';

    private const REGLAS_IMAGEN = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'];

    #[Locked]
    public ?int $productoId = null;

    public string $nombre = '';

    /** Opcional; con la marca, dos marcas del mismo producto se distinguen en buscadores, ticket y factura. */
    public ?string $marca = '';

    public string $contenido = '';

    public ?string $sku = '';

    /** «Auto»: el SKU se genera al guardar (POLERA → POL-001). Encendido por defecto si el producto no tiene código. */
    public bool $sku_auto = true;

    /** Código escrito antes de encender «Auto»: vuelve al campo si se apaga. */
    public string $skuPrevio = '';

    public ?string $codigo_barras = '';

    public ?string $categoria_id = '';

    public string $unidad_medida_id = '';

    public string $stock_minimo = '0';

    public string $precio_consumidor = '';

    public ?string $precio_minorista = '';

    public ?string $precio_mayorista = '';

    public ?string $descripcion = '';

    public bool $activo = true;

    /** Casilla «Controla stock» (ESPECIFICACION_PRODUCTOS_SIN_STOCK.md § 3); GuardarProducto aplica sus reglas. */
    public bool $controla_stock = true;

    /**
     * Casilla «Controla lote y vencimiento» (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 4 y § 5.6): solo con la
     * capacidad, marcada por defecto al crear (D3). GuardarProducto aplica sus reglas y la ignora sin la capacidad.
     */
    public bool $controla_vencimiento = false;

    /** @var TemporaryUploadedFile|null */
    public $imagen = null;

    public bool $quitarImagen = false;

    /**
     * «Este producto tiene tallas y colores» (solo al crear, con la capacidad y para quien puede crear modelos): el
     * formulario muestra la elección de tallas y colores con su cuadrícula (ArmaCombinaciones) y al guardar crea un
     * modelo con GuardarModelo, que genera un producto por combinación (ESPECIFICACION_TALLAS_Y_COLORES.md § 4).
     */
    public bool $con_variantes = false;

    /**
     * @var array<int|string, string> sucursal_id => mínimo propio (vacío = general)
     */
    public array $minimos = [];

    /**
     * «Cargar stock inicial» (solo al crear y si el producto controla stock): una entrada «Inventario inicial» en una
     * sucursal, guardada en la misma transacción que el producto (StockInicial). Con tallas y colores, la sucursal es
     * la misma para todas y cada combinación lleva su cantidad en la cuadrícula.
     */
    public bool $con_stock_inicial = false;

    public string $stock_sucursal_id = '';

    public string $stock_cantidad = '';

    public string $stock_lote = '';

    public string $stock_vencimiento = '';

    /** @var array<string, string> clave «talla-color» => cantidad (vacío = sin stock) */
    public array $stock_combinaciones = [];

    /** Clave de idempotencia del stock inicial: guardar dos veces el mismo formulario no crea dos productos. */
    #[Locked]
    public string $claveStock = '';

    public function mount(?Producto $producto = null): void
    {
        if ($producto === null || ! $producto->exists) {
            $this->authorize('create', Producto::class);
            $this->unidad_medida_id = (string) UnidadMedida::query()->where('activa', true)->orderBy('id')->value('id');
            $this->controla_vencimiento = $this->conLotes();
            $this->claveStock = (string) Str::uuid();
            $sucursales = Sucursal::query()->where('activa', true)->orderBy('id')->limit(2)->pluck('id');
            // Con una sola sucursal ya queda elegida.
            $this->stock_sucursal_id = $sucursales->count() === 1 ? (string) $sucursales->first() : '';

            return;
        }

        $this->authorize('update', $producto);
        $this->productoId = $producto->id;
        $this->nombre = $producto->nombre_base;
        $this->marca = (string) ($producto->marca ?? '');
        $this->contenido = $producto->contenido !== null ? Decimal::simple((string) $producto->contenido) : '';
        $this->sku = (string) ($producto->sku ?? '');
        $this->sku_auto = $this->sku === '';
        $this->skuPrevio = $this->sku;
        $this->codigo_barras = (string) ($producto->codigo_barras ?? '');
        $this->categoria_id = (string) ($producto->categoria_id ?? '');
        $this->unidad_medida_id = (string) $producto->unidad_medida_id;
        $this->stock_minimo = Decimal::simple($producto->stock_minimo);
        $this->precio_consumidor = $producto->precio_consumidor;
        $this->precio_minorista = (string) ($producto->precio_minorista ?? '');
        $this->precio_mayorista = (string) ($producto->precio_mayorista ?? '');
        $this->descripcion = (string) ($producto->descripcion ?? '');
        $this->activo = $producto->activo;
        $this->controla_stock = $producto->controla_stock;
        $this->controla_vencimiento = $producto->controla_vencimiento;
        $this->minimos = StockNivel::query()->where('producto_id', $producto->id)->whereNotNull('stock_minimo')
            ->pluck('stock_minimo', 'sucursal_id')->map(fn ($minimo) => Decimal::simple((string) $minimo))->all();
    }

    public function updatedConVariantes(bool $valor): void
    {
        if ($valor && ! $this->puedeCrearConVariantes()) {
            $this->con_variantes = false;

            return;
        }

        if ($valor) {
            $this->sincronizar();
        }

        $this->resetValidation();
    }

    /** Sin control de stock no hay stock que cargar. */
    public function updatedControlaStock(bool $valor): void
    {
        if (! $valor) {
            $this->con_stock_inicial = false;
            $this->resetErrorBag(['stock_inicial', 'stock_cantidad', 'stock_sucursal_id', 'stock_lote']);
        }
    }

    public function updatedConStockInicial(): void
    {
        $this->resetErrorBag(['stock_inicial', 'stock_cantidad', 'stock_sucursal_id', 'stock_lote']);
    }

    /** Un producto con lote y vencimiento siempre controla stock (ESPECIFICACION_PRODUCTOS_SIN_STOCK.md § 4.6). */
    public function updatedControlaVencimiento(bool $valor): void
    {
        if ($valor) {
            $this->controla_stock = true;
            $this->resetErrorBag('controla_stock');
        }
    }

    /** Valida la imagen apenas se sube, antes de intentar mostrar su vista previa. */
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

    public function guardar(GuardarProducto $accion, ConfigurarMinimosSucursal $minimos, GuardarModelo $guardarModelo, StockInicial $stockInicial): void
    {
        if ($this->productoId !== null) {
            $this->con_stock_inicial = false;
        }

        // El mismo formulario ya se guardó con su stock (doble clic o reintento): no se crea otro producto.
        if ($this->con_stock_inicial && $this->yaGuardado($stockInicial)) {
            return;
        }

        try {
            if ($this->con_variantes) {
                $this->guardarConVariantes($guardarModelo);

                return;
            }

            $this->guardarProducto($accion, $minimos);
        } catch (UniqueConstraintViolationException $e) {
            if (! $this->con_stock_inicial || ! $this->yaGuardado($stockInicial)) {
                throw $e;
            }
        }
    }

    /** Si la clave del stock inicial ya se usó, lleva al producto (o al modelo) que se creó con ella. */
    private function yaGuardado(StockInicial $stockInicial): bool
    {
        $id = $stockInicial->productoDe((int) $this->actor()->empresa_id, $this->claveStock);

        if ($id === null) {
            return false;
        }

        $modeloId = Producto::query()->whereKey($id)->value('modelo_id');
        session()->flash('aviso', __('Este producto ya se había guardado con su stock inicial.'));
        $modeloId === null
            ? $this->redirectRoute('productos.index', navigate: false)
            : $this->redirectRoute('productos.modelos.ver', ['modelo' => (int) $modeloId], navigate: false);

        return true;
    }

    /**
     * Reglas del stock inicial en pantalla (StockInicial y LibroStock vuelven a validar todo al guardar).
     *
     * @return array<string, list<mixed>>
     */
    private function reglasStockInicial(): array
    {
        if (! $this->con_stock_inicial) {
            return [];
        }

        $empresaId = $this->actor()->empresa_id;
        $reglas = ['stock_sucursal_id' => ['required', Rule::exists('sucursales', 'id')->where('empresa_id', $empresaId)->where('activa', true)]];

        if ($this->con_variantes) {
            $reglas['stock_combinaciones.*'] = ['nullable', self::CANTIDAD];
        } else {
            $reglas['stock_cantidad'] = ['required', self::CANTIDAD];
        }

        return $reglas;
    }

    /** @return array<string, string> */
    private function mensajesStockInicial(): array
    {
        return [
            'stock_sucursal_id.required' => __('Elige la sucursal donde está el stock inicial.'),
            'stock_sucursal_id.exists' => __('Elige la sucursal donde está el stock inicial.'),
            'stock_cantidad.required' => __('Escribe la cantidad del stock inicial o desmarca «Cargar stock inicial».'),
            'stock_cantidad.regex' => __('Ingresa una cantidad mayor a 0 (por ejemplo 12 o 1,5).'),
            'stock_combinaciones.*.regex' => __('Cantidad no válida.'),
        ];
    }

    /** @return array{sucursal_id: string, clave: string} */
    private function datosStockInicial(): array
    {
        return ['sucursal_id' => $this->stock_sucursal_id, 'clave' => $this->claveStock];
    }

    private function nombreSucursal(): string
    {
        return (string) Sucursal::query()->whereKey((int) $this->stock_sucursal_id)->value('nombre');
    }

    /** Al encender «Auto» se vacía el campo (el código se genera al guardar); al apagarlo vuelve el que había. */
    public function updatedSkuAuto(): void
    {
        if ($this->sku_auto) {
            $this->skuPrevio = (string) $this->sku;
            $this->sku = '';
            $this->resetErrorBag('sku');
        } else {
            $this->sku = $this->skuPrevio;
        }
    }

    private function guardarProducto(GuardarProducto $accion, ConfigurarMinimosSucursal $minimos): void
    {

        $empresaId = $this->actor()->empresa_id;
        $this->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'marca' => ['nullable', 'string', 'max:'.Producto::MAX_MARCA],
            'contenido' => ['nullable', self::CANTIDAD],
            'sku' => [Rule::excludeIf($this->sku_auto && blank($this->sku)), 'nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9._\-\/]+$/',
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
            'controla_stock' => ['boolean'],
            'controla_vencimiento' => ['boolean'],
            ...$this->reglasStockInicial(),
        ], [
            ...$this->mensajesStockInicial(),
            'contenido.regex' => __('Ingresa una cantidad válida mayor a 0 (por ejemplo 350 o 1,5).'),
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
            'nombre' => __('nombre'),
            'marca' => mb_strtolower($this->etiquetaMarca()),
            'contenido' => __('contenido / presentación'),
            'sku' => __('código / SKU'), 'codigo_barras' => __('código de barras'), 'categoria_id' => __('categoría'),
            'unidad_medida_id' => __('unidad'), 'stock_minimo' => __('stock mínimo'), 'precio_consumidor' => __('precio consumidor'),
            'precio_minorista' => __('precio minorista'), 'precio_mayorista' => __('precio mayorista'), 'descripcion' => __('descripción'),
        ]);

        // ESPECIFICACION_PRODUCTOS_SIN_STOCK.md § 4.6; GuardarProducto y el CHECK de la base lo exigen igual.
        if ($this->conLotes() && $this->controla_vencimiento && ! $this->controla_stock) {
            throw ValidationException::withMessages([
                'controla_stock' => __('Un producto con lote y vencimiento debe controlar stock. Para venderlo sin control de stock, primero desmarca «Controla lote y vencimiento».'),
            ]);
        }

        if (filled($this->contenido)) {
            $norm = Decimal::normalizar($this->contenido, Decimal::ESCALA_CANTIDAD);
            if ($norm === null || Decimal::comparar($norm, '0') <= 0) {
                throw ValidationException::withMessages([
                    'contenido' => __('Ingresa una cantidad mayor a 0 (por ejemplo 350 o 1,5).'),
                ]);
            }
        }

        $contenidoGuardar = filled($this->contenido) ? $this->contenido : null;

        $datos = [
            'nombre' => $this->nombre, 'marca' => $this->marca, 'tipo_venta' => $this->tipoVenta($contenidoGuardar), 'contenido' => $contenidoGuardar,
            'sku' => $this->sku, 'sku_auto' => $this->sku_auto, 'codigo_barras' => $this->codigo_barras,
            'descripcion' => $this->descripcion, 'categoria_id' => $this->categoria_id,
            'unidad_medida_id' => $this->unidad_medida_id, 'stock_minimo' => $this->stock_minimo,
            'precio_consumidor' => $this->precio_consumidor, 'precio_minorista' => $this->precio_minorista,
            'precio_mayorista' => $this->precio_mayorista, 'activo' => $this->activo,
            'controla_stock' => $this->controla_stock,
        ];

        if ($this->conLotes()) {
            $datos['controla_vencimiento'] = $this->controla_vencimiento;
        }

        if ($this->productoId === null) {
            $stock = $this->con_stock_inicial ? $this->datosStockInicial() + [
                'cantidad' => $this->stock_cantidad, 'lote' => $this->stock_lote, 'vencimiento' => $this->stock_vencimiento,
            ] : null;
            $producto = $accion->crear($this->actor(), $datos, $this->imagen, $stock);
            $etiqueta = $producto->sku ?: $producto->nombre;
            session()->flash('aviso', match (true) {
                $stock !== null => __('Producto :sku creado con su stock inicial en :sucursal.', ['sku' => $etiqueta, 'sucursal' => $this->nombreSucursal()]),
                $producto->controla_stock => __('Producto :sku creado. Carga su stock desde Ajustes y traspasos.', ['sku' => $etiqueta]),
                default => __('Producto :sku creado. Se vende sin control de stock.', ['sku' => $etiqueta]),
            });
        } else {
            $producto = $accion->actualizar($this->actor(), Producto::query()->findOrFail($this->productoId), $datos, $this->imagen, $this->quitarImagen);

            // Sin control de stock no hay alertas: los mínimos por sucursal se ocultan y no se guardan.
            if ($this->minimos !== [] && $producto->controla_stock) {
                $minimos->ejecutar($this->actor(), $producto, $this->minimos);
            }

            $etiqueta = $producto->sku ?: $producto->nombre;
            session()->flash('aviso', __('Producto :sku actualizado.', ['sku' => $etiqueta]));
        }

        $this->redirectRoute('productos.index', navigate: false);
    }

    /**
     * Guarda el producto con tallas y colores como un modelo: GuardarModelo crea en una transacción un producto por
     * combinación marcada, con su SKU y código de barras, y vuelve a validar todo (capacidad, permisos, listas de la
     * empresa, SKU y códigos únicos).
     */
    private function guardarConVariantes(GuardarModelo $accion): void
    {
        if ($this->productoId !== null || ! $this->puedeCrearConVariantes()) {
            $this->con_variantes = false;

            return;
        }

        $empresaId = $this->actor()->empresa_id;
        $this->validate([
            'nombre' => ['required', 'string', 'max:'.Variantes::MAX_NOMBRE_MODELO],
            'sku_base' => ['nullable', 'string', 'max:'.Variantes::MAX_SKU_BASE, 'regex:/^[A-Za-z0-9._\-\/]+$/'],
            'categoria_id' => ['nullable', Rule::exists('categorias', 'id')->where('empresa_id', $empresaId)],
            'unidad_medida_id' => ['required', Rule::exists('unidades_medida', 'id')->where('empresa_id', $empresaId)],
            'stock_minimo' => ['required', self::CANTIDAD],
            'precio_consumidor' => ['required', self::PRECIO],
            'precio_minorista' => ['nullable', self::PRECIO],
            'precio_mayorista' => ['nullable', self::PRECIO],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'imagen' => self::REGLAS_IMAGEN,
            ...$this->reglasStockInicial(),
        ], [
            ...$this->mensajesStockInicial(),
            'nombre.max' => __('Con tallas y colores, el nombre admite hasta :max caracteres para que quepan la talla y el color.', ['max' => Variantes::MAX_NOMBRE_MODELO]),
            'sku_base.regex' => __('Usa letras, números, puntos, guiones o barras.'),
            'precio_consumidor.regex' => __('Ingresa un precio válido (por ejemplo 13,00).'),
            'precio_minorista.regex' => __('Ingresa un precio válido (por ejemplo 11,10).'),
            'precio_mayorista.regex' => __('Ingresa un precio válido (por ejemplo 9,80).'),
            'stock_minimo.regex' => __('Ingresa una cantidad mayor o igual a 0.'),
            'imagen.max' => __('La imagen no puede superar 3 MB.'),
        ], [
            'nombre' => __('nombre'), 'sku_base' => __('SKU base'), 'categoria_id' => __('categoría'), 'unidad_medida_id' => __('unidad'),
            'stock_minimo' => __('stock mínimo'), 'precio_consumidor' => __('precio consumidor'),
            'precio_minorista' => __('precio minorista'), 'precio_mayorista' => __('precio mayorista'), 'descripcion' => __('descripción'),
        ]);

        $modelo = $accion->crear($this->actor(), [
            'nombre' => $this->nombre, 'categoria_id' => $this->categoria_id, 'unidad_medida_id' => $this->unidad_medida_id,
            'sku_base' => $this->sku_base, 'descripcion' => $this->descripcion, 'precio_consumidor' => $this->precio_consumidor,
            'precio_minorista' => $this->precio_minorista, 'precio_mayorista' => $this->precio_mayorista, 'stock_minimo' => $this->stock_minimo,
            'grupo_talla_id' => $this->grupo_talla_id, 'tallas' => $this->tallas, 'colores' => $this->colores,
            'combinaciones' => $this->celdasParaGuardar(), 'activo' => true,
        ], $this->imagen, $this->con_stock_inicial ? $this->datosStockInicial() + ['cantidades' => $this->stock_combinaciones] : null);

        session()->flash('aviso', $this->con_stock_inicial
            ? __('Producto :nombre creado con sus tallas y colores y su stock inicial en :sucursal.', ['nombre' => $modelo->nombre, 'sucursal' => $this->nombreSucursal()])
            : __('Producto :nombre creado con sus tallas y colores. Carga su stock desde Compras o Ajustes → Entrada.', ['nombre' => $modelo->nombre]));
        $this->redirectRoute('productos.modelos.ver', ['modelo' => $modelo->id], navigate: false);
    }

    /** El interruptor de tallas y colores: solo al crear, con la capacidad y para quien puede crear modelos. */
    private function puedeCrearConVariantes(): bool
    {
        return $this->productoId === null && Gate::allows('capacidad', Capacidad::Variantes) && Gate::allows('create', Modelo::class);
    }

    /** @return Collection<string, Producto> */
    protected function variantesExistentes(): Collection
    {
        return collect();
    }

    #[Computed]
    public function vistaPreviaNombreCompleto(): string
    {
        $base = $this->vistaPreviaNombreBase();
        if ($base === '') {
            return '';
        }

        $contenido = $this->vistaPreviaContenido();

        return trim($base.' '.$contenido);
    }

    #[Computed]
    public function vistaPreviaNombreBase(): string
    {
        $nombre = trim($this->nombre);

        // Si el campo nombre está vacío, no mostrar nada en la vista previa
        if ($nombre === '') {
            return '';
        }

        return $nombre;
    }

    #[Computed]
    public function vistaPreviaContenido(): string
    {
        $nombre = trim($this->nombre);
        $contenido = trim((string) $this->contenido);

        // Si el nombre está vacío, tampoco se muestra contenido en la vista previa
        if ($nombre === '' || $contenido === '') {
            return '';
        }

        $abreviatura = '';
        if ($this->unidad_medida_id !== '') {
            $abreviatura = (string) (UnidadMedida::query()->where('id', (int) $this->unidad_medida_id)->value('abreviatura') ?? '');
        }

        return trim($contenido.($abreviatura !== '' ? ' '.$abreviatura : ''));
    }

    /** Igual que previewExample en la vista: cómo se venderá según el contenido y la unidad (Producto::tipoVentaPara). */
    #[Computed]
    public function textoEjemplo(): string
    {
        $unidad = $this->unidad_medida_id === '' ? null
            : UnidadMedida::query()->where('id', (int) $this->unidad_medida_id)->first(['abreviatura', 'admite_decimales']);
        $abreviatura = (string) ($unidad->abreviatura ?? '');
        $contenido = trim((string) $this->contenido);

        if ($contenido !== '') {
            $base = $this->vistaPreviaNombreBase();

            return trim(__('Se vende por unidades:').' '.($base !== '' ? $base : 'Cyclone').' '.$contenido.' '.$abreviatura).'.';
        }

        return $unidad !== null && $unidad->admite_decimales
            ? __('Se vende por peso o medida: admite cantidades como 1,5').($abreviatura !== '' ? ' '.$abreviatura : '').'.'
            : __('Se vende por unidades enteras.');
    }

    /**
     * «¿Cómo se vende?» ya no se pregunta: se deduce del contenido y la unidad (Producto::tipoVentaPara). Al editar,
     * si no cambiaron ni el contenido ni la unidad, se conserva el que tenía el producto: guardar otro dato no cambia
     * en silencio si se vende con decimales.
     */
    private function tipoVenta(?string $contenido): string
    {
        $admiteDecimales = (bool) UnidadMedida::query()->where('id', (int) $this->unidad_medida_id)->value('admite_decimales');
        $deducido = Producto::tipoVentaPara($contenido, $admiteDecimales);
        $actual = $this->productoId === null ? null
            : Producto::query()->find($this->productoId, ['id', 'tipo_venta', 'contenido', 'unidad_medida_id']);

        if ($actual === null || (string) $actual->unidad_medida_id !== $this->unidad_medida_id) {
            return $deducido;
        }

        $antes = $actual->contenido === null ? null : Decimal::normalizar((string) $actual->contenido, Decimal::ESCALA_CANTIDAD);
        $ahora = $contenido === null ? null : Decimal::normalizar($contenido, Decimal::ESCALA_CANTIDAD);

        return $antes === $ahora && in_array($actual->tipo_venta, ['unidad', 'medida'], true) ? $actual->tipo_venta : $deducido;
    }

    public function render(): View
    {
        $producto = $this->productoId === null ? null : Producto::query()->find($this->productoId);
        $stock = $producto === null ? collect() : StockNivel::query()->where('producto_id', $producto->id)->pluck('cantidad', 'sucursal_id');

        $conLotes = $this->conLotes();
        $puedeVariantes = $this->puedeCrearConVariantes();

        if ($this->con_variantes && ! $puedeVariantes) {
            $this->con_variantes = false;
        }

        return view('livewire.catalogo.producto-formulario', [
            'producto' => $producto,
            'conLotes' => $conLotes,
            'puedeVariantes' => $puedeVariantes,
            'sucursalesStock' => $this->productoId === null ? Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre']) : collect(),
            'pideLote' => $conLotes && $this->controla_vencimiento && ! $this->con_variantes,
            ...($this->con_variantes ? $this->vistaCombinaciones() : []),
            // Tallas y colores § 4: con la capacidad, una combinación toma del modelo su nombre, clasificación, precios y
            // estado; la ficha los muestra deshabilitados y GuardarProducto los conserva aunque lleguen cambiados.
            'modeloVariante' => $producto?->modelo_id !== null && Gate::allows('capacidad', Capacidad::Variantes)
                ? Modelo::query()->find($producto->modelo_id, ['id', 'nombre', 'imagen_path']) : null,
            'lotes' => $producto === null || ! $conLotes ? [] : app(StockLotes::class)->delProducto((int) $producto->empresa_id, $producto->id),
            'etiquetaMarca' => $this->etiquetaMarca(),
            // Marcas ya usadas en la empresa (scope), para elegirlas y no escribirlas cada vez de otra forma.
            'marcasSugeridas' => $this->con_variantes ? collect() : Producto::query()->whereNotNull('marca')
                ->distinct()->orderBy('marca')->limit(300)->pluck('marca'),
            'categorias' => Categoria::query()->where(fn ($q) => $q->where('activa', true)->orWhere('id', (int) $this->categoria_id))->orderBy('nombre')->get(['id', 'nombre']),
            'unidades' => UnidadMedida::query()->where(fn ($q) => $q->where('activa', true)->orWhere('id', (int) $this->unidad_medida_id))->orderBy('nombre')->get(['id', 'nombre', 'abreviatura', 'admite_decimales']),
            'stockSucursales' => $producto === null ? collect() : Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn (Sucursal $sucursal) => [
                    'sucursal' => $sucursal,
                    'stock' => (string) ($stock->get($sucursal->id) ?? '0'),
                ]),
        ])->title($this->productoId === null ? __('Nuevo producto') : __('Editar producto'));
    }

    /** «Marca / laboratorio» en farmacias; «Marca» en los demás rubros. */
    private function etiquetaMarca(): string
    {
        return $this->actor()->empresa->rubro->etiquetaMarca();
    }

    private function conLotes(): bool
    {
        return Gate::allows('capacidad', Capacidad::LotesVencimientos);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
