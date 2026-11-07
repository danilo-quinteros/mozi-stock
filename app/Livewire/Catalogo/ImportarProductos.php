<?php

namespace App\Livewire\Catalogo;

use App\Domain\Catalogo\Actions\ConfirmarImportacionProductos;
use App\Domain\Catalogo\Actions\PrepararImportacionProductos;
use App\Domain\Catalogo\Importacion\CamposImportacion;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Categoria;
use App\Models\ImportacionProducto;
use App\Models\ImportacionProductoFila;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Support\Csv;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Importación de productos desde cualquier hoja de cálculo, sin plantilla: archivo → columnas
 * → valores (categorías y unidades) → revisión → resultado. Las filas viven en la base de
 * datos; el componente solo guarda las elecciones del usuario. Toda regla y escritura está
 * en PrepararImportacionProductos y ConfirmarImportacionProductos.
 */
#[Title('Importar productos')]
class ImportarProductos extends Component
{
    use MuestraAvisos, WithFileUploads, WithPagination;

    #[Url(as: 'id')]
    public ?int $importacionId = null;

    /** @var TemporaryUploadedFile|null */
    public $archivo = null;

    public string $hoja = '0';

    public string $fila_encabezados = '1';

    /** @var array<int|string, string> índice de columna => campo ('' = no importar) */
    public array $mapeo = [];

    public string $unidad_defecto_id = '';

    /** 'si' | 'no' | '' (sin responder) */
    public string $usar_stock = '';

    public string $sucursal_stock_id = '';

    /** @var list<array{clave: string, original: string, filas: int, destino: string, nombre: string}> */
    public array $categorias = [];

    /** @var list<array{clave: string, original: string, filas: int, destino: string, nombre: string, abreviatura: string, decimales: bool}> */
    public array $unidades = [];

    #[Url(as: 'ver', except: 'todas')]
    public string $filtro = 'todas';

    #[Locked]
    public string $firma = '';

    public bool $confirmando = false;

    public bool $omitirErrores = false;

    public function mount(): void
    {
        $this->authorize('create', Producto::class);

        $importacion = $this->importacion();

        if ($importacion === null || $importacion->estado === ImportacionProducto::CANCELADA) {
            $this->importacionId = null;

            return;
        }

        $this->cargarFormulario($importacion);
    }

    public function updatedArchivo(): void
    {
        $this->validate(
            ['archivo' => ['required', 'file', 'max:'.PrepararImportacionProductos::MAX_KB, 'extensions:xlsx,ods,csv,txt']],
            ['archivo.max' => __('El archivo no puede superar 10 MB.'), 'archivo.extensions' => __('Sube un archivo .xlsx, .ods o .csv. Si tienes un .xls antiguo, ábrelo en Excel y guárdalo como .xlsx.')],
        );

        try {
            $importacion = app(PrepararImportacionProductos::class)->crear($this->actor(), $this->archivo);
        } finally {
            $this->archivo = null;
        }

        $this->importacionId = $importacion->id;
        $this->cargarFormulario($importacion);
    }

    public function updatedHoja(): void
    {
        $importacion = $this->importacionAbierta();
        app(PrepararImportacionProductos::class)->cambiarHoja($this->actor(), $importacion, (int) $this->hoja);
        $this->cargarFormulario($importacion);
    }

    public function updatedFilaEncabezados(): void
    {
        $importacion = $this->importacionAbierta();
        app(PrepararImportacionProductos::class)->cambiarEncabezados($this->actor(), $importacion, (int) $this->fila_encabezados);
        $this->cargarFormulario($importacion);
    }

    public function updatedFiltro(): void
    {
        $this->resetPage();
    }

    public function guardarColumnas(PrepararImportacionProductos $accion): void
    {
        $importacion = $this->importacionAbierta();

        $accion->asignarColumnas($this->actor(), $importacion, [
            'fila_encabezados' => (int) $this->fila_encabezados,
            'mapeo' => $this->mapeo,
            'unidad_defecto_id' => ctype_digit($this->unidad_defecto_id) ? (int) $this->unidad_defecto_id : null,
            'usar_stock' => match ($this->usar_stock) {
                'si' => true,
                'no' => false,
                default => null,
            },
            'sucursal_stock_id' => ctype_digit($this->sucursal_stock_id) ? (int) $this->sucursal_stock_id : null,
        ]);

        $this->resetPage();
        $this->cargarFormulario($importacion);
    }

    public function guardarValores(PrepararImportacionProductos $accion): void
    {
        $importacion = $this->importacionAbierta();
        $elegidos = ['categorias' => [], 'unidades' => []];

        foreach ($this->categorias as $categoria) {
            $elegidos['categorias'][$categoria['clave']] = ['destino' => $this->destino($categoria['destino']), 'nombre' => $categoria['nombre']];
        }

        foreach ($this->unidades as $unidad) {
            $elegidos['unidades'][$unidad['clave']] = [
                'destino' => $this->destino($unidad['destino']), 'nombre' => $unidad['nombre'],
                'abreviatura' => $unidad['abreviatura'], 'decimales' => (bool) $unidad['decimales'],
            ];
        }

        $accion->asignarValores($this->actor(), $importacion, $elegidos);
        $this->resetPage();
        $this->cargarFormulario($importacion);
    }

    public function volverAColumnas(PrepararImportacionProductos $accion): void
    {
        $importacion = $this->importacionAbierta();
        $accion->volverAColumnas($this->actor(), $importacion);
        $this->cargarFormulario($importacion);
    }

    public function volverAValores(PrepararImportacionProductos $accion): void
    {
        $importacion = $this->importacionAbierta();
        $accion->volverAValores($this->actor(), $importacion);
        $this->cargarFormulario($importacion);
    }

    public function pedirConfirmacion(bool $omitirErrores): void
    {
        $this->resetErrorBag('confirmar');
        $this->omitirErrores = $omitirErrores;
        $this->confirmando = true;
    }

    public function confirmar(ConfirmarImportacionProductos $accion): void
    {
        $importacion = $this->importacion() ?? abort(404);

        try {
            $resultado = $accion->ejecutar($this->actor(), $importacion, $this->omitirErrores, $this->firma);
        } catch (ValidationException $e) {
            // El resumen pudo cambiar al revalidar: se muestra el actualizado.
            $this->confirmando = false;
            $this->cargarFormulario($importacion->refresh());

            throw $e;
        }

        $this->confirmando = false;
        $this->cargarFormulario($importacion->refresh());
        $this->avisar(trans_choice('{1} Se importó 1 producto.|[2,*] Se importaron :total productos.', (int) ($resultado['creados'] ?? 0), ['total' => (int) ($resultado['creados'] ?? 0)]));
    }

    public function cancelar(PrepararImportacionProductos $accion): void
    {
        $accion->cancelar($this->actor(), $this->importacionAbierta());
        $this->reiniciar();
        $this->avisar(__('Importación cancelada. No se creó ningún producto.'));
    }

    public function nuevaImportacion(): void
    {
        $this->reiniciar();
    }

    public function descargarErrores(PrepararImportacionProductos $accion): StreamedResponse
    {
        $importacion = $this->importacion() ?? abort(404);
        $this->authorize('create', Producto::class);
        $columnas = $accion->columnas($importacion);

        return Csv::descargar(
            "importacion-{$importacion->id}-filas-con-errores.csv",
            [__('Fila'), __('Errores'), ...array_column($columnas, 'encabezado')],
            function () use ($importacion, $columnas): iterable {
                $filas = ImportacionProductoFila::query()
                    ->where('importacion_id', $importacion->id)
                    ->where('estado', ImportacionProductoFila::ERROR)
                    ->orderBy('fila')
                    ->lazy(500);

                foreach ($filas as $fila) {
                    yield [
                        $fila->fila,
                        implode(' | ', $fila->errores ?? []),
                        ...array_map(fn (array $columna) => (string) ($fila->celdas[$columna['indice']] ?? ''), $columnas),
                    ];
                }
            },
        );
    }

    public function render(PrepararImportacionProductos $preparar): View
    {
        $importacion = $this->importacion();

        if ($importacion?->estado === ImportacionProducto::CANCELADA) {
            $importacion = null;
        }

        $paso = match ($importacion?->estado) {
            ImportacionProducto::COLUMNAS => 'columnas',
            ImportacionProducto::VALORES => 'valores',
            ImportacionProducto::REVISION => 'revision',
            ImportacionProducto::COMPLETADA => 'resultado',
            default => 'archivo',
        };

        $datos = ['importacion' => $importacion, 'paso' => $paso, 'campos' => CamposImportacion::etiquetas()];

        if ($paso === 'archivo') {
            $datos['recientes'] = ImportacionProducto::query()->with('usuario:id,name')
                ->where('estado', '!=', ImportacionProducto::CANCELADA)->latest('id')->limit(5)->get();
        }

        if ($paso === 'columnas') {
            $datos['columnas'] = $preparar->columnas($importacion);
            $datos['primerasFilas'] = $importacion->filas()->orderBy('fila')->limit(20)->get(['fila', 'celdas']);
            $datos['unidadesActivas'] = UnidadMedida::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre', 'abreviatura']);
            $datos['sucursales'] = Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre']);
            $datos['columnaStock'] = array_search('stock', $this->mapeo, true);
        }

        if ($paso === 'valores') {
            $datos['categoriasExistentes'] = Categoria::query()->orderBy('nombre')->get(['id', 'nombre', 'activa']);
            $datos['unidadesActivas'] = UnidadMedida::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre', 'abreviatura']);
        }

        if ($paso === 'revision' || $paso === 'resultado') {
            $consulta = $importacion->filas()->where('fila', '>', $importacion->fila_encabezados)->orderBy('fila');

            match ($this->filtro) {
                'validas' => $consulta->where('estado', ImportacionProductoFila::VALIDA),
                'errores' => $consulta->where('estado', ImportacionProductoFila::ERROR),
                'existentes' => $consulta->where('estado', ImportacionProductoFila::EXISTENTE),
                'advertencias' => $consulta->where('estado', ImportacionProductoFila::VALIDA)->whereNotNull('advertencias'),
                default => null,
            };

            $datos['filas'] = $consulta->paginate(25, ['id', 'fila', 'estado', 'normalizado', 'errores', 'advertencias', 'producto_id']);
            $datos['resumen'] = $importacion->resumen ?? [];
            $datos['sucursalStock'] = $importacion->sucursal_stock_id === null ? null
                : Sucursal::query()->whereKey($importacion->sucursal_stock_id)->value('nombre');
        }

        return view('livewire.catalogo.importar-productos', $datos);
    }

    private function cargarFormulario(ImportacionProducto $importacion): void
    {
        $this->resetValidation();
        $this->hoja = (string) $importacion->hoja;
        $this->fila_encabezados = (string) $importacion->fila_encabezados;
        $this->mapeo = [];

        foreach ($importacion->mapeo ?? [] as $campo => $indice) {
            $this->mapeo[$indice] = $campo;
        }

        $this->unidad_defecto_id = (string) ($importacion->unidad_defecto_id
            ?? UnidadMedida::query()->where('activa', true)->where('nombre', 'Unidad')->value('id')
            ?? UnidadMedida::query()->where('activa', true)->orderBy('id')->value('id'));
        $this->usar_stock = match ($importacion->usar_stock) {
            true => 'si',
            false => 'no',
            default => '',
        };
        $sucursales = Sucursal::query()->where('activa', true)->pluck('id');
        $this->sucursal_stock_id = (string) ($importacion->sucursal_stock_id ?? ($sucursales->count() === 1 ? $sucursales->first() : ''));

        $this->categorias = [];

        foreach ($importacion->valores['categorias'] ?? [] as $clave => $valor) {
            $this->categorias[] = [
                'clave' => (string) $clave, 'original' => (string) $valor['nombre'], 'filas' => (int) $valor['filas'],
                'destino' => (string) $valor['destino'], 'nombre' => (string) ($valor['nombre_nuevo'] ?? $valor['nombre']),
            ];
        }

        $this->unidades = [];

        foreach ($importacion->valores['unidades'] ?? [] as $clave => $valor) {
            $this->unidades[] = [
                'clave' => (string) $clave, 'original' => (string) $valor['nombre'], 'filas' => (int) $valor['filas'],
                'destino' => (string) $valor['destino'], 'nombre' => (string) ($valor['nombre_nuevo'] ?? $valor['nombre']),
                'abreviatura' => (string) ($valor['abreviatura'] ?? ''), 'decimales' => (bool) ($valor['decimales'] ?? false),
            ];
        }

        $this->firma = (string) ($importacion->resumen['firma'] ?? '');
    }

    private function reiniciar(): void
    {
        $this->reset('importacionId', 'archivo', 'hoja', 'fila_encabezados', 'mapeo', 'unidad_defecto_id', 'usar_stock',
            'sucursal_stock_id', 'categorias', 'unidades', 'filtro', 'firma', 'confirmando', 'omitirErrores');
        $this->resetValidation();
        $this->resetPage();
    }

    private function destino(string $valor): string|int
    {
        return ctype_digit($valor) ? (int) $valor : $valor;
    }

    private function importacion(): ?ImportacionProducto
    {
        return $this->importacionId === null ? null : ImportacionProducto::query()->find($this->importacionId);
    }

    private function importacionAbierta(): ImportacionProducto
    {
        $importacion = $this->importacion() ?? abort(404);

        if (! $importacion->abierta()) {
            throw ValidationException::withMessages(['importacion' => __('Esta importación ya terminó. Sube el archivo de nuevo para empezar otra.')]);
        }

        return $importacion;
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
