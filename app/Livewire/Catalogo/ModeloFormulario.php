<?php

namespace App\Livewire\Catalogo;

use App\Domain\Catalogo\Actions\GuardarModelo;
use App\Domain\Catalogo\Variantes;
use App\Enums\Capacidad;
use App\Livewire\Concerns\ArmaCombinaciones;
use App\Livewire\Concerns\ExigeCapacidad;
use App\Models\Categoria;
use App\Models\Modelo;
use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Crear y editar un modelo (ESPECIFICACION_TALLAS_Y_COLORES.md § 4): página completa con los datos del modelo, el
 * grupo de tallas, los colores y la vista previa en cuadrícula tallas × colores (ArmaCombinaciones). Nunca asigna
 * stock. Toda regla vive en GuardarModelo. El catálogo crea modelos desde «Nuevo producto» con «Este producto tiene
 * tallas y colores»; esta pantalla queda para editarlos (y su ruta de creación sigue respondiendo).
 */
class ModeloFormulario extends Component
{
    use ArmaCombinaciones, ExigeCapacidad, WithFileUploads;

    private const PRECIO = 'regex:/^\d{1,15}([.,]\d{1,2})?$/';

    private const CANTIDAD = 'regex:/^\d{1,15}([.,]\d{1,3})?$/';

    private const REGLAS_IMAGEN = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'];

    #[Locked]
    public ?int $modeloId = null;

    public string $nombre = '';

    public ?string $categoria_id = '';

    public string $unidad_medida_id = '';

    public ?string $descripcion = '';

    public string $precio_consumidor = '';

    public ?string $precio_minorista = '';

    public ?string $precio_mayorista = '';

    public string $stock_minimo = '0';

    public bool $activo = true;

    /** @var TemporaryUploadedFile|null */
    public $imagen = null;

    public bool $quitarImagen = false;

    public function mount(?Modelo $modelo = null): void
    {
        $this->exigirCapacidad(Capacidad::Variantes);

        if ($modelo === null || ! $modelo->exists) {
            $this->authorize('create', Modelo::class);
            $this->unidad_medida_id = (string) (UnidadMedida::query()->where('activa', true)->where('nombre', 'Unidad')->value('id')
                ?? UnidadMedida::query()->where('activa', true)->orderBy('id')->value('id'));
            $this->sincronizar();

            return;
        }

        $this->authorize('update', $modelo);
        $this->modeloId = $modelo->id;
        $this->nombre = $modelo->nombre;
        $this->categoria_id = (string) ($modelo->categoria_id ?? '');
        $this->unidad_medida_id = (string) $modelo->unidad_medida_id;
        $this->sku_base = (string) ($modelo->sku_base ?? '');
        $this->descripcion = (string) ($modelo->descripcion ?? '');
        $this->precio_consumidor = $modelo->precio_consumidor;
        $this->precio_minorista = (string) ($modelo->precio_minorista ?? '');
        $this->precio_mayorista = (string) ($modelo->precio_mayorista ?? '');
        $this->stock_minimo = Decimal::simple($modelo->stock_minimo);
        $this->activo = $modelo->activo;
        $this->grupo_talla_id = (string) ($modelo->grupo_talla_id ?? '');

        $variantes = $this->variantes();
        $activas = $variantes->filter(fn (Producto $v): bool => $v->activo);
        $this->tallas = array_values($activas->pluck('talla_id')->filter()->unique()->map(fn ($id): string => (string) $id)->all());
        $this->colores = array_values($activas->pluck('color_id')->filter()->unique()->map(fn ($id): string => (string) $id)->all());

        foreach ($variantes as $clave => $variante) {
            $this->combinaciones[$clave] = ['crear' => $variante->activo, 'sku' => (string) ($variante->sku ?? ''),
                'codigo_barras' => (string) ($variante->codigo_barras ?? ''), 'sugerido' => ''];
        }

        $this->sincronizar();

        // Las celdas de la cuadrícula que no existen se dejaron sin crear al guardar: siguen sin marcar. Solo las que
        // aparecen al agregar una talla o un color nacen marcadas.
        foreach ($this->cuadricula() as $clave) {
            if (! $variantes->has($clave)) {
                $this->combinaciones[$clave]['crear'] = false;
            }
        }
    }

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

    public function guardar(GuardarModelo $accion): void
    {
        $this->exigirCapacidad(Capacidad::Variantes);
        $this->validate([
            'nombre' => ['required', 'string', 'max:'.Variantes::MAX_NOMBRE_MODELO],
            'sku_base' => ['nullable', 'string', 'max:'.Variantes::MAX_SKU_BASE, 'regex:/^[A-Za-z0-9._\-\/]+$/'],
            'precio_consumidor' => ['required', self::PRECIO],
            'precio_minorista' => ['nullable', self::PRECIO],
            'precio_mayorista' => ['nullable', self::PRECIO],
            'stock_minimo' => ['required', self::CANTIDAD],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'imagen' => self::REGLAS_IMAGEN,
        ], [
            'sku_base.regex' => __('Usa letras, números, puntos, guiones o barras.'),
            'precio_consumidor.regex' => __('Ingresa un precio válido (por ejemplo 13,00).'),
            'precio_minorista.regex' => __('Ingresa un precio válido (por ejemplo 11,10).'),
            'precio_mayorista.regex' => __('Ingresa un precio válido (por ejemplo 9,80).'),
            'stock_minimo.regex' => __('Ingresa una cantidad mayor o igual a 0.'),
            'imagen.max' => __('La imagen no puede superar 3 MB.'),
        ], [
            'nombre' => __('nombre'), 'sku_base' => __('SKU base'), 'precio_consumidor' => __('precio consumidor'),
            'precio_minorista' => __('precio minorista'), 'precio_mayorista' => __('precio mayorista'),
            'stock_minimo' => __('stock mínimo'), 'descripcion' => __('descripción'),
        ]);

        $datos = [
            'nombre' => $this->nombre, 'categoria_id' => $this->categoria_id, 'unidad_medida_id' => $this->unidad_medida_id,
            'sku_base' => $this->sku_base, 'descripcion' => $this->descripcion, 'precio_consumidor' => $this->precio_consumidor,
            'precio_minorista' => $this->precio_minorista, 'precio_mayorista' => $this->precio_mayorista, 'stock_minimo' => $this->stock_minimo,
            'grupo_talla_id' => $this->grupo_talla_id, 'tallas' => $this->tallas, 'colores' => $this->colores,
            'combinaciones' => $this->celdasParaGuardar(), 'activo' => $this->activo,
        ];

        if ($this->modeloId === null) {
            $modelo = $accion->crear($this->actor(), $datos, $this->imagen);
            session()->flash('aviso', __('Modelo :nombre creado. Carga su stock desde Compras o Ajustes → Entrada.', ['nombre' => $modelo->nombre]));
        } else {
            $modelo = $accion->actualizar($this->actor(), Modelo::query()->findOrFail($this->modeloId), $datos, $this->imagen, $this->quitarImagen);
            session()->flash('aviso', __('Modelo :nombre actualizado.', ['nombre' => $modelo->nombre]));
        }

        $this->redirectRoute('productos.modelos.ver', ['modelo' => $modelo->id], navigate: false);
    }

    public function render(): View
    {
        $modelo = $this->modeloId === null ? null : Modelo::query()->find($this->modeloId);

        return view('livewire.catalogo.modelo-formulario', [
            'modelo' => $modelo,
            'categorias' => Categoria::query()->where(fn ($q) => $q->where('activa', true)->orWhere('id', (int) $this->categoria_id))->orderBy('nombre')->get(['id', 'nombre']),
            'unidades' => UnidadMedida::query()->where(fn ($q) => $q->where('activa', true)->orWhere('id', (int) $this->unidad_medida_id))->orderBy('nombre')->get(['id', 'nombre', 'abreviatura']),
            ...$this->vistaCombinaciones((int) ($modelo->grupo_talla_id ?? 0)),
        ])->title($this->modeloId === null ? __('Nuevo modelo') : __('Editar modelo'));
    }

    /** @return Collection<string, Producto> */
    protected function variantesExistentes(): Collection
    {
        return $this->modeloId === null ? collect() : $this->variantes();
    }

    /**
     * Variantes del modelo por clave «talla-color» (todas, también las desactivadas).
     *
     * @return Collection<string, Producto>
     */
    private function variantes(): Collection
    {
        return Producto::query()->where('modelo_id', $this->modeloId)->orderBy('id')
            ->get(['id', 'nombre', 'sku', 'codigo_barras', 'talla_id', 'color_id', 'activo'])
            ->keyBy(fn (Producto $v): string => Variantes::clave($v->talla_id, $v->color_id));
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
