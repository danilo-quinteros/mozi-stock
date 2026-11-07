<?php

namespace App\Livewire\Catalogo;

use App\Domain\Catalogo\Actions\GuardarCategoria;
use App\Domain\Catalogo\Actions\GuardarProducto;
use App\Domain\Catalogo\Actions\GuardarUnidadMedida;
use App\Domain\Catalogo\Consultas\ListadoProductos;
use App\Domain\CatalogoExterno\Actions\SincronizarEmpresa;
use App\Domain\Facturacion\CatalogoEmpresa;
use App\Livewire\Concerns\MuestraAvisos;
use App\Livewire\Concerns\UsaSucursalActiva;
use App\Models\CatalogoExternoProducto;
use App\Models\Categoria;
use App\Models\EmpresaProveedorExterno;
use App\Models\Producto;
use App\Models\ProveedorExterno;
use App\Models\SiatCatalogo;
use App\Models\Sucursal;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Support\Decimal;
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

    #[Url(as: 'stock', except: '')]
    public string $filtroStock = '';

    #[Url(as: 'tab', except: 'productos')]
    public string $pestana = 'productos';

    #[Locked]
    public ?int $editandoProductoId = null;

    public string $editandoPrecio = '';

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

    // Catálogo Cyclone
    /** @var list<string> ids de catalogo_externo_productos marcados (los checkbox envían texto) */
    public array $seleccionadosCyclone = [];

    #[Url(except: '')]
    public string $buscarCyclone = '';

    #[Url(except: '')]
    public string $lineaCyclone = '';

    #[Url(except: 'todos')]
    public string $estadoCyclone = 'todos'; // todos | en_catalogo | disponibles

    public bool $seleccionarTodosCyclone = false;

    #[Locked]
    public ?int $confirmandoDejarVenderId = null;

    /** @var array{producto_id: int, externo_id: string, nombre: string, stock_total: string, unidad: string, sucursales: array<string, string>}|null */
    public ?array $alertaStockIndividual = null;

    public bool $confirmandoDejarVenderBulk = false;

    /** @var array<int, array{producto_id: int, externo_id: string, nombre: string, stock_total: string, unidad: string, sucursales: array<string, string>}> */
    public array $alertaStockBulk = [];

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
        if (in_array($propiedad, ['buscar', 'categoria', 'sucursal', 'filtroStock'], true)) {
            $this->cancelarEdicionPrecio();
            $this->resetPage();
        }
        if (in_array($propiedad, ['buscarCyclone', 'lineaCyclone', 'estadoCyclone'], true)) {
            $this->resetPage('cyclonePage');
        }
    }

    public function iniciarEdicionPrecio(int $productoId): void
    {
        $actor = $this->actor();
        abort_unless($actor->esAdministrador(), 403);

        $producto = Producto::query()
            ->where('empresa_id', $actor->empresa_id)
            ->findOrFail($productoId);

        $this->authorize('update', $producto);

        $this->editandoProductoId = $producto->id;
        $this->editandoPrecio = Decimal::simple((string) $producto->precio_consumidor);
        $this->resetValidation('editandoPrecio');
    }

    public function cancelarEdicionPrecio(): void
    {
        $this->editandoProductoId = null;
        $this->editandoPrecio = '';
        $this->resetValidation('editandoPrecio');
    }

    public function guardarPrecio(GuardarProducto $accion): void
    {
        $actor = $this->actor();
        abort_unless($actor->esAdministrador(), 403);

        if ($this->editandoProductoId === null) {
            return;
        }

        $producto = Producto::query()
            ->where('empresa_id', $actor->empresa_id)
            ->findOrFail($this->editandoProductoId);

        $this->validate([
            'editandoPrecio' => ['required', 'regex:/^\d{1,15}([.,]\d{1,2})?$/'],
        ], [
            'editandoPrecio.required' => __('Ingresa un precio.'),
            'editandoPrecio.regex' => __('Ingresa un precio válido en Bs (por ejemplo 15,00).'),
        ], [
            'editandoPrecio' => __('precio consumidor'),
        ]);

        $producto = $accion->actualizarPrecioConsumidor($actor, $producto, $this->editandoPrecio);

        $this->editandoProductoId = null;
        $this->editandoPrecio = '';
        $this->avisar(__('Precio consumidor actualizado a :precio.', ['precio' => Decimal::bs($producto->precio_consumidor)]));
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

    /**
     * @param  list<int|string>  $idsPagina
     */
    public function toggleSeleccionarTodos(array $idsPagina): void
    {
        $strIds = array_map('strval', $idsPagina);
        $seleccionadosActuales = array_map('strval', $this->seleccionadosCyclone);
        $interseccion = array_intersect($strIds, $seleccionadosActuales);
        $todosSeleccionados = count($interseccion) === count($strIds);

        if ($todosSeleccionados) {
            $this->seleccionadosCyclone = array_values(array_diff($seleccionadosActuales, $strIds));
            $this->seleccionarTodosCyclone = false;
        } else {
            $this->seleccionadosCyclone = array_values(array_unique(array_merge($seleccionadosActuales, $strIds)));
            $this->seleccionarTodosCyclone = true;
        }
    }

    public function desmarcarCyclone(): void
    {
        $this->seleccionadosCyclone = [];
        $this->seleccionarTodosCyclone = false;
    }

    public function agregarProducto(int $catalogoExternoId, SincronizarEmpresa $sincronizador): void
    {
        $actor = $this->actor();
        abort_unless($actor->esAdministrador(), 403);

        $ext = CatalogoExternoProducto::query()->findOrFail($catalogoExternoId);
        $proveedor = ProveedorExterno::query()->where('codigo', 'cyclone')->firstOrFail();

        $sincronizador->ejecutar($actor->empresa, $proveedor, $actor, soloExternoIds: [$ext->externo_id]);
        $this->avisar(__('Producto agregado a tu catálogo.'));
    }

    public function agregarMarcados(SincronizarEmpresa $sincronizador): void
    {
        $actor = $this->actor();
        abort_unless($actor->esAdministrador(), 403);

        if (empty($this->seleccionadosCyclone)) {
            return;
        }

        $externoIds = CatalogoExternoProducto::query()
            ->whereIn('id', $this->seleccionadosCyclone)
            ->pluck('externo_id')
            ->all();

        if (empty($externoIds)) {
            return;
        }

        $proveedor = ProveedorExterno::query()->where('codigo', 'cyclone')->firstOrFail();
        $sincronizador->ejecutar($actor->empresa, $proveedor, $actor, soloExternoIds: $externoIds);

        $total = count($externoIds);
        $this->desmarcarCyclone();
        $this->avisar(__(':total productos agregados a tu catálogo.', ['total' => $total]));
    }

    public function solicitarDejarVender(int $catalogoExternoId, SincronizarEmpresa $sincronizador): void
    {
        $actor = $this->actor();
        abort_unless($actor->esAdministrador(), 403);

        $ext = CatalogoExternoProducto::query()->findOrFail($catalogoExternoId);
        $proveedor = ProveedorExterno::query()->where('codigo', 'cyclone')->firstOrFail();

        $detalles = $sincronizador->obtenerDetalleStock($actor->empresa, $proveedor, [$ext->externo_id]);

        if (! empty($detalles)) {
            $this->confirmandoDejarVenderId = $catalogoExternoId;
            $this->alertaStockIndividual = $detalles[0];
        } else {
            $sincronizador->dejarDeVender($actor->empresa, $proveedor, [$ext->externo_id], $actor);
            $this->avisar(__('Producto retirado de tu catálogo.'));
        }
    }

    public function cancelarDejarVender(): void
    {
        $this->confirmandoDejarVenderId = null;
        $this->alertaStockIndividual = null;
    }

    public function confirmarDejarVender(SincronizarEmpresa $sincronizador): void
    {
        $actor = $this->actor();
        abort_unless($actor->esAdministrador(), 403);

        if ($this->confirmandoDejarVenderId === null) {
            return;
        }

        $ext = CatalogoExternoProducto::query()->findOrFail($this->confirmandoDejarVenderId);
        $proveedor = ProveedorExterno::query()->where('codigo', 'cyclone')->firstOrFail();

        $sincronizador->dejarDeVender($actor->empresa, $proveedor, [$ext->externo_id], $actor);

        $this->confirmandoDejarVenderId = null;
        $this->alertaStockIndividual = null;
        $this->avisar(__('Producto retirado de la venta activa. Sus existencias e historial se conservaron.'));
    }

    public function solicitarDejarVenderMarcados(SincronizarEmpresa $sincronizador): void
    {
        $actor = $this->actor();
        abort_unless($actor->esAdministrador(), 403);

        if (empty($this->seleccionadosCyclone)) {
            return;
        }

        $externoIds = CatalogoExternoProducto::query()
            ->whereIn('id', $this->seleccionadosCyclone)
            ->pluck('externo_id')
            ->all();

        if (empty($externoIds)) {
            return;
        }

        $proveedor = ProveedorExterno::query()->where('codigo', 'cyclone')->firstOrFail();
        $detalles = $sincronizador->obtenerDetalleStock($actor->empresa, $proveedor, $externoIds);

        if (! empty($detalles)) {
            $this->confirmandoDejarVenderBulk = true;
            $this->alertaStockBulk = $detalles;
        } else {
            $res = $sincronizador->dejarDeVender($actor->empresa, $proveedor, $externoIds, $actor);
            $this->desmarcarCyclone();
            $this->avisar(__(':total productos retirados de tu catálogo.', ['total' => $res['total']]));
        }
    }

    public function cancelarDejarVenderBulk(): void
    {
        $this->confirmandoDejarVenderBulk = false;
        $this->alertaStockBulk = [];
    }

    public function confirmarDejarVenderBulk(SincronizarEmpresa $sincronizador): void
    {
        $actor = $this->actor();
        abort_unless($actor->esAdministrador(), 403);

        if (empty($this->seleccionadosCyclone)) {
            return;
        }

        $externoIds = CatalogoExternoProducto::query()
            ->whereIn('id', $this->seleccionadosCyclone)
            ->pluck('externo_id')
            ->all();

        if (empty($externoIds)) {
            return;
        }

        $proveedor = ProveedorExterno::query()->where('codigo', 'cyclone')->firstOrFail();
        $res = $sincronizador->dejarDeVender($actor->empresa, $proveedor, $externoIds, $actor);

        $this->confirmandoDejarVenderBulk = false;
        $this->alertaStockBulk = [];
        $this->desmarcarCyclone();

        $this->avisar(__(':total productos retirados de tu catálogo (:desactivados con inventario conservado, :eliminados eliminados).', [
            'total' => $res['total'],
            'desactivados' => $res['desactivados'],
            'eliminados' => $res['eliminados'],
        ]));
    }

    public function sincronizarCyclone(SincronizarEmpresa $sincronizador): void
    {
        $actor = $this->actor();
        abort_unless($actor->esAdministrador(), 403);

        $proveedor = ProveedorExterno::query()->where('codigo', 'cyclone')->firstOrFail();
        $res = $sincronizador->ejecutar($actor->empresa, $proveedor, $actor, soloActualizarVinculados: true);

        $this->avisar(__('Sincronización con Cyclone: :creados nuevos, :act actualizados, :sin sin cambios.', [
            'creados' => $res['creados'],
            'act' => $res['actualizados'],
            'sin' => $res['sin_cambios'],
        ]));
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

        $esRevendedoraCyclone = EmpresaProveedorExterno::query()
            ->where('empresa_id', $empresaId)
            ->where('activo', true)
            ->whereHas('proveedor', fn ($q) => $q->where('codigo', 'cyclone'))
            ->exists();

        if ($this->pestana === 'cyclone' && ! $esRevendedoraCyclone) {
            $this->pestana = 'productos';
        }

        $productosCyclone = collect();
        $lineasCyclone = collect();

        if ($esRevendedoraCyclone) {
            $proveedorCyclone = ProveedorExterno::query()->where('codigo', 'cyclone')->first();
            if ($proveedorCyclone) {
                $lineasCyclone = CatalogoExternoProducto::query()
                    ->where('proveedor_externo_id', $proveedorCyclone->id)
                    ->whereNotNull('linea_nombre')
                    ->where('linea_nombre', '!=', '')
                    ->distinct()
                    ->orderBy('linea_nombre')
                    ->pluck('linea_nombre');

                if ($this->pestana === 'cyclone') {
                    $queryCyclone = CatalogoExternoProducto::query()
                        ->where('catalogo_externo_productos.proveedor_externo_id', $proveedorCyclone->id)
                        ->where('catalogo_externo_productos.disponible', true)
                        ->leftJoin('producto_vinculos_externos as pve', function ($join) use ($empresaId, $proveedorCyclone): void {
                            $join->on('pve.externo_id', '=', 'catalogo_externo_productos.externo_id')
                                ->where('pve.empresa_id', '=', $empresaId)
                                ->where('pve.proveedor_externo_id', '=', $proveedorCyclone->id);
                        })
                        ->leftJoin('productos as p', function ($join) use ($empresaId): void {
                            $join->on('p.id', '=', 'pve.producto_id')
                                ->where('p.empresa_id', '=', $empresaId);
                        })
                        ->select('catalogo_externo_productos.*')
                        ->selectRaw('p.id as local_producto_id')
                        ->selectRaw('CASE WHEN p.id IS NOT NULL AND p.activo = 1 THEN 1 ELSE 0 END as en_catalogo');

                    if (filled($this->buscarCyclone)) {
                        $t = trim($this->buscarCyclone);
                        $patron = '%'.addcslashes($t, '\\%_').'%';
                        $queryCyclone->where(function ($q) use ($patron): void {
                            $q->where('catalogo_externo_productos.nombre_limpio', 'like', $patron)
                                ->orWhere('catalogo_externo_productos.codigo', 'like', $patron)
                                ->orWhere('catalogo_externo_productos.descripcion', 'like', $patron)
                                ->orWhere('catalogo_externo_productos.linea_nombre', 'like', $patron);
                        });
                    }

                    if (filled($this->lineaCyclone)) {
                        $queryCyclone->where('catalogo_externo_productos.linea_nombre', $this->lineaCyclone);
                    }

                    if ($this->estadoCyclone === 'en_catalogo') {
                        $queryCyclone->whereNotNull('p.id')->where('p.activo', true);
                    } elseif ($this->estadoCyclone === 'disponibles') {
                        $queryCyclone->where(fn ($q) => $q->whereNull('p.id')->orWhere('p.activo', false));
                    }

                    $productosCyclone = $queryCyclone->orderBy('catalogo_externo_productos.nombre_limpio')
                        ->paginate(25, ['*'], 'cyclonePage');
                }
            }
        }

        $filtroStockValido = in_array($this->filtroStock, ['con_stock', 'sin_stock'], true) ? $this->filtroStock : null;

        return view('livewire.catalogo.productos', [
            'productos' => ListadoProductos::consulta($empresaId, $sucursal?->id, $this->buscar, $categoriaId, $filtroStockValido)->paginate(25),
            'sucursalVista' => $sucursal,
            'sucursales' => $actor->esAdministrador() ? Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre']) : collect(),
            'categorias' => Categoria::query()->withCount('productos')->orderBy('nombre')->get(),
            'unidades' => UnidadMedida::query()->orderBy('nombre')->get(),
            'totalProductos' => Producto::query()->where('activo', true)->count(),
            'alertas' => $sucursal === null ? 0 : ListadoProductos::contarAlertas($empresaId, $sucursal->id),
            'administra' => $actor->esAdministrador(),
            'empresaSiat' => $empresaSiat,
            'unidadesSin' => $unidadesSin,
            'esRevendedoraCyclone' => $esRevendedoraCyclone,
            'productosCyclone' => $productosCyclone,
            'lineasCyclone' => $lineasCyclone,
        ]);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
