<?php

namespace App\Livewire\Catalogo;

use App\Domain\Catalogo\Actions\GuardarCategoria;
use App\Domain\Catalogo\Actions\GuardarUnidadMedida;
use App\Domain\Catalogo\Consultas\ListadoProductos;
use App\Domain\Facturacion\CatalogoEmpresa;
use App\Livewire\Concerns\MuestraAvisos;
use App\Livewire\Concerns\UsaSucursalActiva;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\SiatCatalogo;
use App\Models\Sucursal;
use App\Models\UnidadMedida;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use MoziStudios\Siat\Sincronizacion\Catalogo;

#[Title('Productos')]
class Productos extends Component
{
    use MuestraAvisos, UsaSucursalActiva, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    #[Url(except: '')]
    public string $categoria = '';

    #[Url(except: '')]
    public string $sucursal = '';

    public string $pestana = 'productos';

    public bool $formCategoria = false;

    #[Locked]
    public ?int $categoriaId = null;

    public string $categoriaNombre = '';

    public bool $categoriaActiva = true;

    public bool $formUnidad = false;

    #[Locked]
    public ?int $unidadId = null;

    public string $unidadNombre = '';

    public string $unidadAbreviatura = '';

    public string $unidadMedidaSin = '';

    public bool $unidadDecimales = false;

    public bool $unidadActiva = true;

    public function mount(): void
    {
        $this->authorize('viewAny', Producto::class);
        $this->aplicarSucursalActiva();

        if (is_string($mensaje = session('aviso'))) {
            $this->avisar($mensaje);
        }
    }

    public function updated(string $propiedad): void
    {
        if (in_array($propiedad, ['buscar', 'categoria', 'sucursal'], true)) {
            $this->resetPage();
        }
    }

    public function nuevaCategoria(): void
    {
        $this->authorize('create', Categoria::class);
        $this->reset('categoriaId', 'categoriaNombre', 'categoriaActiva');
        $this->resetValidation();
        $this->formCategoria = true;
    }

    public function editarCategoria(int $id): void
    {
        $categoria = Categoria::query()->findOrFail($id);
        $this->authorize('update', $categoria);
        $this->resetValidation();
        $this->categoriaId = $categoria->id;
        $this->categoriaNombre = $categoria->nombre;
        $this->categoriaActiva = $categoria->activa;
        $this->formCategoria = true;
    }

    public function guardarCategoria(GuardarCategoria $accion): void
    {
        $this->validate([
            'categoriaNombre' => ['required', 'string', 'max:80',
                Rule::unique('categorias', 'nombre')->where('empresa_id', $this->actor()->empresa_id)->ignore($this->categoriaId)],
        ], [], ['categoriaNombre' => __('nombre')]);

        if ($this->categoriaId === null) {
            $accion->crear($this->actor(), $this->categoriaNombre);
            $this->avisar(__('Categoría creada.'));
        } else {
            $accion->actualizar($this->actor(), Categoria::query()->findOrFail($this->categoriaId), $this->categoriaNombre, $this->categoriaActiva);
            $this->avisar(__('Categoría actualizada.'));
        }

        $this->formCategoria = false;
    }

    public function nuevaUnidad(): void
    {
        $this->authorize('create', UnidadMedida::class);
        $this->reset('unidadId', 'unidadNombre', 'unidadAbreviatura', 'unidadDecimales', 'unidadActiva', 'unidadMedidaSin');
        $this->resetValidation();
        $this->formUnidad = true;
    }

    public function editarUnidad(int $id): void
    {
        $unidad = UnidadMedida::query()->findOrFail($id);
        $this->authorize('update', $unidad);
        $this->resetValidation();
        $this->unidadId = $unidad->id;
        $this->unidadNombre = $unidad->nombre;
        $this->unidadAbreviatura = $unidad->abreviatura;
        $this->unidadDecimales = $unidad->admite_decimales;
        $this->unidadActiva = $unidad->activa;
        $this->unidadMedidaSin = (string) ($unidad->unidad_medida_sin ?? '');
        $this->formUnidad = true;
    }

    public function guardarUnidad(GuardarUnidadMedida $accion): void
    {
        $this->validate([
            'unidadNombre' => ['required', 'string', 'max:60',
                Rule::unique('unidades_medida', 'nombre')->where('empresa_id', $this->actor()->empresa_id)->ignore($this->unidadId)],
            'unidadAbreviatura' => ['required', 'string', 'max:15'],
            'unidadDecimales' => ['boolean'],
            'unidadMedidaSin' => ['nullable', 'integer', 'min:1'],
        ], [], ['unidadNombre' => __('nombre'), 'unidadAbreviatura' => __('abreviatura'), 'unidadMedidaSin' => __('unidad de medida SIN')]);

        $datos = [
            'nombre' => $this->unidadNombre,
            'abreviatura' => $this->unidadAbreviatura,
            'admite_decimales' => $this->unidadDecimales,
            'unidad_medida_sin' => $this->unidadMedidaSin === '' ? null : (int) $this->unidadMedidaSin,
        ];

        if ($this->unidadId === null) {
            $accion->crear($this->actor(), $datos);
            $this->avisar(__('Unidad creada.'));
        } else {
            $accion->actualizar($this->actor(), UnidadMedida::query()->findOrFail($this->unidadId), $datos + ['activa' => $this->unidadActiva]);
            $this->avisar(__('Unidad actualizada.'));
        }

        $this->formUnidad = false;
    }

    public function cerrarFormularios(): void
    {
        $this->formCategoria = false;
        $this->formUnidad = false;
        $this->resetValidation();
    }

    public function render(): View
    {
        $actor = $this->actor();
        $empresaId = (int) $actor->empresa_id;
        $empresaSiat = (bool) $actor->empresa?->siat_habilitado;
        $sucursal = ListadoProductos::sucursalParaVista($actor, $this->sucursal);
        $categoriaId = ctype_digit($this->categoria) ? (int) $this->categoria : null;

        $unidadesSin = collect();
        if ($empresaSiat) {
            $unidadesSin = CatalogoEmpresa::opciones($empresaId, Catalogo::UnidadesMedida);
            if ($unidadesSin->isEmpty()) {
                $unidadesSin = SiatCatalogo::withoutGlobalScopes()
                    ->where('catalogo', Catalogo::UnidadesMedida->name)
                    ->orderByRaw('CAST(codigo AS UNSIGNED)')
                    ->get(['id', 'codigo', 'descripcion']);
            }
        }

        return view('livewire.catalogo.productos', [
            'productos' => ListadoProductos::consulta($empresaId, $sucursal?->id, $this->buscar, $categoriaId)->paginate(25),
            'sucursalVista' => $sucursal,
            'sucursales' => $actor->esAdministrador() ? Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre']) : collect(),
            'categorias' => Categoria::query()->withCount('productos')->orderBy('nombre')->get(),
            'unidades' => UnidadMedida::query()->orderBy('nombre')->get(),
            'totalProductos' => Producto::query()->where('activo', true)->count(),
            'alertas' => $sucursal === null ? 0 : ListadoProductos::contarAlertas($empresaId, $sucursal->id),
            'administra' => $actor->esAdministrador(),
            'empresaSiat' => $empresaSiat,
            'unidadesSin' => $unidadesSin,
        ]);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
