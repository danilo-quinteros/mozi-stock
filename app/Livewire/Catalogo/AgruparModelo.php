<?php

namespace App\Livewire\Catalogo;

use App\Domain\Catalogo\Actions\AgruparEnModelo;
use App\Domain\Catalogo\AdivinarVariante;
use App\Domain\Catalogo\Variantes;
use App\Enums\Capacidad;
use App\Livewire\Concerns\ExigeCapacidad;
use App\Models\Categoria;
use App\Models\Color;
use App\Models\GrupoTalla;
use App\Models\Modelo;
use App\Models\Producto;
use App\Models\Talla;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * «Agrupar en modelo» (ESPECIFICACION_TALLAS_Y_COLORES.md § 9, opción B): página completa a la que se llega desde el
 * catálogo con los productos sueltos marcados. Se elige un modelo existente o se crea uno con los datos del primer
 * producto; la tabla propone la talla y el color de cada producto a partir de su nombre (AdivinarVariante) y el
 * usuario los confirma; la vista previa muestra qué cambia en cada uno y «Confirmar» lo aplica con AgruparEnModelo,
 * que vuelve a validar todo en una transacción. Solo el administrador, con la capacidad activa.
 */
class AgruparModelo extends Component
{
    use ExigeCapacidad;

    private const PRECIO = 'regex:/^\d{1,15}([.,]\d{1,2})?$/';

    private const CANTIDAD = 'regex:/^\d{1,15}([.,]\d{1,3})?$/';

    /** @var list<int> */
    #[Locked]
    public array $productoIds = [];

    /** 'nuevo' o 'existente'. */
    public string $destino = 'nuevo';

    public string $modelo_id = '';

    public string $nombre = '';

    public ?string $categoria_id = '';

    /** Unidad del modelo nuevo: la de los productos (todos deben compartirla). */
    #[Locked]
    public string $unidad_medida_id = '';

    public ?string $sku_base = '';

    public string $precio_consumidor = '';

    public ?string $precio_minorista = '';

    public ?string $precio_mayorista = '';

    public string $stock_minimo = '0';

    public string $grupo_talla_id = '';

    /**
     * Talla y color elegidos por producto (ids como texto; '' = sin talla o sin color).
     *
     * @var array<int|string, array{talla_id: string, color_id: string}>
     */
    public array $asignaciones = [];

    /**
     * Vista previa calculada por AgruparEnModelo al pulsar «Revisar cambios»; se descarta ante cualquier cambio. Es
     * solo para mostrar: confirmar vuelve a calcular y validar todo en el servidor.
     *
     * @var list<array<string, mixed>>
     */
    public array $previa = [];

    public function mount(): void
    {
        $this->exigirCapacidad(Capacidad::Variantes);
        $this->authorize('create', Modelo::class);

        $ids = array_slice(array_values(array_unique(array_map('intval', array_filter(explode(',', (string) request()->query('productos', '')),
            fn (string $id): bool => ctype_digit(trim($id)) && (int) $id > 0)))), 0, AgruparEnModelo::MAXIMO);
        // El scope de empresa deja fuera los ids de otra empresa; los que ya son variantes no se ofrecen.
        $productos = $ids === [] ? collect() : Producto::query()->whereIn('id', $ids)->whereNull('modelo_id')->orderBy('id')->get();

        if ($productos->isEmpty()) {
            session()->flash('aviso', __('Marca en el catálogo los productos sueltos que quieres agrupar en un modelo.'));
            $this->redirectRoute('productos.index');

            return;
        }

        // Datos del primer producto marcado (§ 9.2), en el orden en que se marcaron.
        $primero = $productos->sortBy(fn (Producto $p): int => (int) array_search($p->id, $ids, true))->first();
        $this->productoIds = array_values(array_filter($ids, fn (int $id): bool => $productos->contains('id', $id)));
        $this->unidad_medida_id = (string) $primero->unidad_medida_id;
        $this->categoria_id = (string) ($primero->categoria_id ?? '');
        $this->precio_consumidor = Decimal::simple((string) $primero->precio_consumidor);
        $this->precio_minorista = $primero->precio_minorista === null ? '' : Decimal::simple((string) $primero->precio_minorista);
        $this->precio_mayorista = $primero->precio_mayorista === null ? '' : Decimal::simple((string) $primero->precio_mayorista);
        $this->stock_minimo = Decimal::simple((string) $primero->stock_minimo);

        // Grupo de tallas: el que más coincide con los nombres; después se propone talla y color de cada producto.
        $tallas = Talla::query()->where('activa', true)->whereHas('grupo', fn ($q) => $q->where('activo', true))->get(['id', 'nombre', 'grupo_talla_id']);
        $porGrupo = [];

        foreach ($productos as $producto) {
            $talla = AdivinarVariante::desdeNombre($producto->nombre_base, $this->opciones($tallas), [])['talla_id'];

            if ($talla !== null) {
                $grupo = (int) $tallas->firstWhere('id', $talla)?->grupo_talla_id;
                $porGrupo[$grupo] = ($porGrupo[$grupo] ?? 0) + 1;
            }
        }

        arsort($porGrupo);
        $this->grupo_talla_id = $porGrupo === [] ? '' : (string) array_key_first($porGrupo);
        $this->adivinar($productos, true);
        $base = AdivinarVariante::desdeNombre($primero->nombre_base, $this->opciones($this->tallasDelGrupo()), $this->opciones($this->coloresActivos()))['base'];
        $this->nombre = Str::limit($base, Variantes::MAX_NOMBRE_MODELO, '');
    }

    public function updated(string $propiedad): void
    {
        $this->previa = [];

        // Al cambiar de grupo (o de modelo destino) las tallas elegidas dejan de valer: se vuelven a proponer.
        if (in_array($propiedad, ['grupo_talla_id', 'destino', 'modelo_id'], true)) {
            $this->adivinar(Producto::query()->whereIn('id', $this->productoIds)->get(), false);
        }
    }

    public function quitarProducto(int $id): void
    {
        $this->productoIds = array_values(array_filter($this->productoIds, fn (int $actual): bool => $actual !== $id));
        unset($this->asignaciones[$id]);
        $this->previa = [];

        if ($this->productoIds === []) {
            $this->redirectRoute('productos.index');
        }
    }

    public function revisar(AgruparEnModelo $accion): void
    {
        $this->exigirCapacidad(Capacidad::Variantes);
        $this->validarFicha();
        $this->previa = $accion->vistaPrevia($this->actor(), $this->productoIds, $this->modeloDestino(), $this->datosModelo(), $this->asignaciones);
    }

    public function confirmar(AgruparEnModelo $accion): void
    {
        $this->exigirCapacidad(Capacidad::Variantes);
        $this->validarFicha();
        $modelo = $accion->ejecutar($this->actor(), $this->productoIds, $this->modeloDestino(), $this->datosModelo(), $this->asignaciones);

        session()->flash('aviso', trans_choice(':count producto agrupado en :modelo. Conserva su stock y su historial.|:count productos agrupados en :modelo. Conservan su stock y su historial.',
            count($this->productoIds), ['modelo' => $modelo->nombre]));
        $this->redirectRoute('productos.modelos.ver', $modelo);
    }

    public function render(): View
    {
        $productos = Producto::query()->whereIn('id', $this->productoIds)->with(['categoria:id,nombre', 'unidad:id,abreviatura'])->get()->keyBy('id');
        // Stock total en todas las sucursales (una consulta): se conserva tal cual.
        $stock = $this->productoIds === [] ? [] : DB::table('stock_niveles')->where('empresa_id', $this->actor()->empresa_id)
            ->whereIn('producto_id', $this->productoIds)->groupBy('producto_id')->selectRaw('producto_id, SUM(cantidad) as total')
            ->pluck('total', 'producto_id')->map(fn ($v): string => (string) $v)->all();
        $unidades = $productos->pluck('unidad_medida_id')->unique()->values()->all();
        $existente = $this->destino === 'existente' ? $this->modeloElegido() : null;

        return view('livewire.catalogo.agrupar-modelo', [
            'productos' => collect($this->productoIds)->map(fn (int $id): ?Producto => $productos->get($id))->filter(),
            'stock' => $stock,
            'categorias' => Categoria::query()->where(fn ($q) => $q->where('activa', true)->orWhere('id', (int) $this->categoria_id))->orderBy('nombre')->get(['id', 'nombre']),
            'nombresCategorias' => Categoria::query()->pluck('nombre', 'id'),
            'unidad' => UnidadMedida::query()->find((int) $this->unidad_medida_id, ['id', 'nombre', 'abreviatura']),
            'unidadesMezcladas' => count($unidades) > 1,
            'grupos' => GrupoTalla::query()->where('activo', true)->orderBy('orden')->orderBy('nombre')->get(['id', 'nombre']),
            'modelos' => Modelo::query()->where('activo', true)->whereIn('unidad_medida_id', $unidades === [] ? [0] : $unidades)->orderBy('nombre')->get(['id', 'nombre', 'grupo_talla_id']),
            'modeloElegido' => $existente,
            'tallas' => $this->tallasDelGrupo(),
            'colores' => $this->coloresActivos(),
        ])->title(__('Agrupar en modelo'));
    }

    /**
     * Propone talla y color de cada producto; con $todo también el color (al cambiar de grupo se conserva el elegido).
     *
     * @param  Collection<int, Producto>  $productos
     */
    private function adivinar(Collection $productos, bool $todo): void
    {
        $tallas = $this->opciones($this->tallasDelGrupo());
        $colores = $this->opciones($this->coloresActivos());

        foreach ($productos as $producto) {
            $propuesta = AdivinarVariante::desdeNombre($producto->nombre_base, $tallas, $colores);
            $this->asignaciones[$producto->id] = [
                'talla_id' => (string) ($propuesta['talla_id'] ?? ''),
                'color_id' => $todo ? (string) ($propuesta['color_id'] ?? '') : ($this->asignaciones[$producto->id]['color_id'] ?? (string) ($propuesta['color_id'] ?? '')),
            ];
        }
    }

    /**
     * @return Collection<int, Talla>
     */
    private function tallasDelGrupo(): Collection
    {
        $grupoId = $this->destino === 'existente' ? $this->modeloElegido()?->grupo_talla_id : (ctype_digit($this->grupo_talla_id) ? (int) $this->grupo_talla_id : null);

        return $grupoId === null ? collect() : Talla::query()->where('grupo_talla_id', $grupoId)->where('activa', true)->orderBy('orden')->orderBy('id')->get(['id', 'nombre', 'grupo_talla_id']);
    }

    /**
     * @return Collection<int, Color>
     */
    private function coloresActivos(): Collection
    {
        return Color::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'muestra_hex']);
    }

    /**
     * @param  Collection<int, Talla>|Collection<int, Color>  $lista
     * @return list<array{id: int, nombre: string}>
     */
    private function opciones(Collection $lista): array
    {
        return array_values($lista->map(fn (Talla|Color $opcion): array => ['id' => (int) $opcion->id, 'nombre' => (string) $opcion->nombre])->all());
    }

    private function modeloElegido(): ?Modelo
    {
        return ctype_digit($this->modelo_id) ? Modelo::query()->where('activo', true)->find((int) $this->modelo_id, ['id', 'nombre', 'grupo_talla_id', 'categoria_id', 'precio_consumidor', 'unidad_medida_id']) : null;
    }

    private function modeloDestino(): ?int
    {
        return $this->destino === 'existente' ? (ctype_digit($this->modelo_id) ? (int) $this->modelo_id : 0) : null;
    }

    private function validarFicha(): void
    {
        if ($this->destino === 'existente') {
            $this->validate(['modelo_id' => ['required']], ['modelo_id.required' => __('Elige el modelo.')]);

            return;
        }

        $this->validate([
            'nombre' => ['required', 'string', 'max:'.Variantes::MAX_NOMBRE_MODELO],
            'sku_base' => ['nullable', 'string', 'max:'.Variantes::MAX_SKU_BASE, 'regex:/^[A-Za-z0-9._\-\/]+$/'],
            'precio_consumidor' => ['required', self::PRECIO],
            'precio_minorista' => ['nullable', self::PRECIO],
            'precio_mayorista' => ['nullable', self::PRECIO],
            'stock_minimo' => ['required', self::CANTIDAD],
        ], [
            'sku_base.regex' => __('Usa letras, números, puntos, guiones o barras.'),
            'precio_consumidor.regex' => __('Ingresa un precio válido (por ejemplo 13,00).'),
            'precio_minorista.regex' => __('Ingresa un precio válido (por ejemplo 11,10).'),
            'precio_mayorista.regex' => __('Ingresa un precio válido (por ejemplo 9,80).'),
            'stock_minimo.regex' => __('Ingresa una cantidad mayor o igual a 0.'),
        ], [
            'nombre' => __('nombre'), 'sku_base' => __('SKU base'), 'precio_consumidor' => __('precio consumidor'),
            'precio_minorista' => __('precio minorista'), 'precio_mayorista' => __('precio mayorista'), 'stock_minimo' => __('stock mínimo'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function datosModelo(): array
    {
        return [
            'nombre' => $this->nombre, 'categoria_id' => $this->categoria_id, 'unidad_medida_id' => $this->unidad_medida_id,
            'sku_base' => $this->sku_base, 'precio_consumidor' => $this->precio_consumidor, 'precio_minorista' => $this->precio_minorista,
            'precio_mayorista' => $this->precio_mayorista, 'stock_minimo' => $this->stock_minimo, 'grupo_talla_id' => $this->grupo_talla_id,
        ];
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
