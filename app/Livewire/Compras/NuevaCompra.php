<?php

namespace App\Livewire\Compras;

use App\Domain\Catalogo\Actions\AsignarPreciosIniciales;
use App\Domain\Compras\Actions\RegistrarCompra;
use App\Domain\Compras\CalculadoraCompra;
use App\Domain\Inventario\DatosLote;
use App\Domain\Inventario\LibroLotes;
use App\Livewire\Concerns\CapturaProductos;
use App\Livewire\Concerns\CreaProveedorRapido;
use App\Livewire\Concerns\EligeCaja;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Compra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Nueva compra (compra-nueva.html V3 y Cuentas por pagar § 13): al contado, a crédito o con
 * pago parcial; con saldo se abre su cuenta por pagar. Los importes en pantalla son
 * informativos: RegistrarCompra recalcula el total y valida el pago en el servidor.
 */
#[Title('Nueva compra')]
class NuevaCompra extends Component
{
    use CapturaProductos, CreaProveedorRapido, EligeCaja, MuestraAvisos;

    public ?string $proveedor_id = null;

    public ?string $sucursal_id = null;

    public string $referencia = '';

    public string $condicion = 'contado';

    public string $pagado = '';

    public string $vencimiento = '';

    public string $metodo = 'efectivo';

    public string $efectivo = '';

    public string $electronico = '';

    public bool $confirmando = false;

    /**
     * Clave de idempotencia de esta pantalla: un doble envío no registra dos compras.
     */
    #[Locked]
    public string $clave = '';

    /**
     * «Ingreso de materia prima» (ESPECIFICACION_PRODUCCION.md § 4.2): la misma compra, con el buscador limitado a
     * materias primas. Solo con Producción y para el administrador; si no, es una compra normal.
     */
    #[Locked]
    public bool $soloMateriaPrima = false;

    public function mount(): void
    {
        $this->authorize('create', Compra::class);
        $this->soloMateriaPrima = request()->boolean('materia_prima') && Gate::allows('usar-produccion');
        $this->clave = (string) Str::uuid();
        $primera = Auth::user()?->sucursalActivaId() ?? Sucursal::query()->where('activa', true)->orderBy('nombre')->value('id');
        $this->sucursal_id = $primera === null ? null : (string) $primera;
        $this->elegirCajaPorDefecto();
    }

    /**
     * Cada fila lleva un uid: en productos con vencimiento el mismo producto puede ir en varias líneas, una por lote
     * (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 4). En los demás productos, lote y vencimiento se ignoran.
     *
     * `precio` es el precio de venta opcional de los productos que aún no tienen precio (AsignarPreciosIniciales).
     *
     * @return array{id: int, cantidad: string, costo: string, uid: string, lote: string, vencimiento: string, precio: string}
     */
    protected function nuevaLinea(int $productoId): array
    {
        return ['id' => $productoId, 'cantidad' => '1', 'costo' => $this->ultimoCosto($productoId), 'uid' => 'l'.Str::lower(Str::random(10)), 'lote' => '', 'vencimiento' => '', 'precio' => ''];
    }

    /**
     * Últimos costos ya consultados en esta petición (producto_id => costo, o null si nunca se compró).
     *
     * @var array<int, string|null>
     */
    protected array $ultimosCostos = [];

    /**
     * La línea nueva empieza con el costo unitario de la última compra del producto en la empresa (la línea de compra
     * registrada más reciente, de cualquier proveedor); se puede cambiar en la línea. Sin compras anteriores queda
     * vacío. Es solo una sugerencia: RegistrarCompra valida el costo escrito.
     */
    private function ultimoCosto(int $productoId): string
    {
        // nuevaLinea(0) solo se usa para saber qué columnas tiene la línea (EligeVariantes::selectorPideCosto).
        if ($productoId <= 0) {
            return '';
        }

        $this->prepararLineasNuevas([$productoId]);

        return $this->ultimosCostos[$productoId] ?? '';
    }

    /**
     * Consulta de una vez el último costo de los productos que se van a agregar (marcados en el panel, combinaciones
     * de la cuadrícula): dos consultas en total, por el índice (empresa_id, producto_id) de compra_items.
     *
     * @param  list<int>  $productoIds
     */
    protected function prepararLineasNuevas(array $productoIds): void
    {
        $faltan = array_values(array_diff(array_unique($productoIds), array_keys($this->ultimosCostos)));

        if ($faltan === []) {
            return;
        }

        $empresaId = (int) Auth::user()?->empresa_id;
        $ultimas = DB::table('compra_items')->where('empresa_id', $empresaId)->whereIn('producto_id', $faltan)
            ->groupBy('producto_id')->selectRaw('MAX(id) as id')->pluck('id')->all();
        $costos = $ultimas === [] ? collect() : DB::table('compra_items')->where('empresa_id', $empresaId)->whereIn('id', $ultimas)
            ->pluck('costo_unitario', 'producto_id');

        foreach ($faltan as $id) {
            $this->ultimosCostos[$id] = isset($costos[$id]) ? (string) $costos[$id] : null;
        }
    }

    /**
     * Ids de los productos de la tabla que piden precio de venta: sin precio, sueltos, de venta, y solo si quien
     * compra puede definir precios (administrador). Una consulta.
     *
     * @return array<int, true>
     */
    private function productosSinPrecio(): array
    {
        $ids = array_values(array_unique(array_column($this->lineas, 'id')));

        if ($ids === [] || ! Gate::allows('create', Producto::class)) {
            return [];
        }

        $sinPrecio = [];

        foreach (Producto::query()->whereIn('id', $ids)->get(['id', 'clase', 'modelo_id', 'precio_consumidor']) as $producto) {
            if (AsignarPreciosIniciales::admite($producto)) {
                $sinPrecio[$producto->id] = true;
            }
        }

        return $sinPrecio;
    }

    /**
     * Precios escritos, por producto (si el producto va en varias líneas por lote, vale el primero que se escribió).
     *
     * @return array<int, string> producto_id => precio tal como se escribió
     */
    private function preciosEscritos(): array
    {
        $sinPrecio = $this->productosSinPrecio();
        $precios = [];

        foreach ($this->lineas as $linea) {
            $valor = trim($linea['precio'] ?? '');

            if ($valor !== '' && isset($sinPrecio[$linea['id']]) && ! isset($precios[$linea['id']])) {
                $precios[$linea['id']] = $valor;
            }
        }

        return $precios;
    }

    /**
     * @return list<string>
     */
    protected function clasesCaptura(): array
    {
        return $this->soloMateriaPrima ? [Producto::CLASE_MATERIA_PRIMA] : [Producto::CLASE_VENTA, Producto::CLASE_MATERIA_PRIMA];
    }

    /** Comprar algo del catálogo del proveedor externo que aún no se vende (no en «Ingreso de materia prima»). */
    protected function ofreceCatalogoExterno(): bool
    {
        return ! $this->soloMateriaPrima;
    }

    /**
     * «Otro lote»: agrega debajo una línea del mismo producto con el mismo costo y el lote en blanco.
     */
    public function otroLote(string $uid): void
    {
        $indice = array_search($uid, array_column($this->lineas, 'uid'), true);

        if (! is_int($indice) || ! isset($this->productosConLote()[$this->lineas[$indice]['id']])) {
            return;
        }

        $linea = $this->nuevaLinea($this->lineas[$indice]['id']);
        $linea['costo'] = $this->lineas[$indice]['costo'] ?? '';
        array_splice($this->lineas, $indice + 1, 0, [$linea]);
    }

    /**
     * Productos de la tabla que se compran por lote: marcados y con la capacidad activa (como en el servidor).
     *
     * @return array<int, true>
     */
    private function productosConLote(): array
    {
        return app(LibroLotes::class)->productosConLote((int) $this->actor()->empresa_id, array_column($this->lineas, 'id'));
    }

    /**
     * Al corregir el lote, el vencimiento o el precio de venta de una línea se quita su error (y el aviso general, si ya
     * no quedan).
     */
    public function updated(string $propiedad): void
    {
        if (preg_match('/^lineas\.\d+\.(lote|vencimiento|precio)$/', $propiedad) !== 1) {
            return;
        }

        $this->resetErrorBag($propiedad);

        if (! $this->getErrorBag()->hasAny(['lineas.*.lote', 'lineas.*.vencimiento', 'lineas.*.precio'])) {
            $this->resetErrorBag('lineas');
        }
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }

    public function revisar(): void
    {
        $this->resetValidation();
        $this->validate([
            'sucursal_id' => ['required'],
            'proveedor_id' => ['required'],
            'referencia' => ['nullable', 'string', 'max:150'],
            'condicion' => ['required', 'in:contado,credito,parcial'],
            'metodo' => ['required', 'in:efectivo,electronico,mixto'],
            'vencimiento' => [$this->condicion === 'contado' ? 'nullable' : 'required'],
            'lineas' => ['required', 'array', 'min:1'],
        ], [
            'lineas.required' => __('Agrega al menos un producto.'),
            'lineas.min' => __('Agrega al menos un producto.'),
            'proveedor_id.required' => __('Selecciona el proveedor de la compra.'),
            'vencimiento.required' => __('La fecha de vencimiento es obligatoria cuando queda saldo pendiente.'),
        ], ['sucursal_id' => __('sucursal'), 'metodo' => __('medio de pago')]);

        // Lote y vencimiento por línea (§ 5.1). RegistrarCompra lo vuelve a validar en el servidor.
        $conLote = $this->productosConLote();
        $errores = [];

        foreach ($this->lineas as $indice => $linea) {
            if (! isset($conLote[$linea['id']])) {
                continue;
            }

            $numero = DatosLote::numero($linea['lote'] ?? '');

            if ($numero === null) {
                $errores["lineas.{$indice}.lote"] = __('Escribe el número de lote.');
            } elseif (mb_strlen($numero) > DatosLote::MAX_NUMERO) {
                $errores["lineas.{$indice}.lote"] = __('Hasta :maximo caracteres.', ['maximo' => DatosLote::MAX_NUMERO]);
            }

            if (DatosLote::vencimiento($linea['vencimiento'] ?? '') === null) {
                $errores["lineas.{$indice}.vencimiento"] = __('Escribe dd/mm/aaaa o mm/aaaa.');
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores + ['lineas' => __('Completa el lote y el vencimiento de los productos marcados.')]);
        }

        // Precio de venta opcional (productos sin precio): si se escribe, debe ser válido. Se vuelve a validar al guardar.
        $sinPrecio = $this->productosSinPrecio();

        foreach ($this->lineas as $indice => $linea) {
            $valor = trim($linea['precio'] ?? '');

            if ($valor !== '' && isset($sinPrecio[$linea['id']]) && AsignarPreciosIniciales::precio($valor) === null) {
                $errores["lineas.{$indice}.precio"] = __('Precio mayor a 0, con hasta 2 decimales.');
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores + ['lineas' => __('Revisa los precios de venta marcados.')]);
        }

        $this->confirmando = true;
    }

    public function confirmar(RegistrarCompra $accion, AsignarPreciosIniciales $precios): void
    {
        $this->confirmando = false;
        $lineas = array_map(fn (array $linea) => [
            'producto_id' => $linea['id'], 'cantidad' => $linea['cantidad'], 'costo' => $linea['costo'] ?? '',
            'lote' => $linea['lote'] ?? null, 'vencimiento' => $linea['vencimiento'] ?? null,
        ], $this->lineas);

        $actor = $this->actor();
        $preciosVenta = $this->preciosEscritos();

        // Compra y precios de venta, juntos o nada: si un precio no se puede guardar, la compra tampoco se registra.
        // Repetir la confirmación no duplica la compra (clave) ni cambia precios ya puestos (solo llena los que están en 0).
        $compra = DB::transaction(fn (): Compra => tap($accion->ejecutar($actor, $this->datosCaja() + [
            'sucursal_id' => $this->sucursal_id === null ? null : (int) $this->sucursal_id,
            'proveedor_id' => blank($this->proveedor_id) ? null : (int) $this->proveedor_id,
            'referencia' => $this->referencia,
            'lineas' => $lineas,
            'condicion' => $this->condicion,
            'pagado' => $this->pagado,
            'vencimiento' => $this->vencimiento === '' ? null : $this->vencimiento,
            'metodo' => $this->metodo,
            'efectivo' => $this->efectivo,
            'electronico' => $this->electronico,
            'clave' => $this->clave,
        ]), fn () => $precios->ejecutar($actor, $preciosVenta)));

        session()->flash('aviso', $compra->condicion === 'contado'
            ? __(':codigo registrada: stock ingresado y pago de :importe.', ['codigo' => $compra->codigo(), 'importe' => Decimal::bs($compra->total)])
            : __(':codigo registrada: stock ingresado, pagado :pagado y :saldo en cuentas por pagar.', ['codigo' => $compra->codigo(),
                'pagado' => Decimal::bs($compra->pagado), 'saldo' => Decimal::bs(Decimal::restar($compra->total, $compra->pagado, 2))]));
        $this->redirectRoute('compras.ver', $compra, navigate: false);
    }

    public function render(): View
    {
        $sucursalId = blank($this->sucursal_id) ? null : (int) $this->sucursal_id;
        $datos = $this->datosDeLineas($sucursalId);
        $conLote = $this->lineas === [] ? [] : $this->productosConLote();
        $filas = [];
        $vencidos = [];
        // Producción PR17: stock máximo de la materia prima. Solo avisa; el servidor no bloquea.
        $sobreMaximo = [];
        $comprado = [];
        $unidades = '0';
        $total = '0.00';
        $valido = true;

        foreach ($this->lineas as $indice => $linea) {
            $producto = $datos['productos']->get($linea['id']);

            if ($producto === null) {
                continue;
            }

            $clave = (string) $this->claveLinea($linea);
            $lote = isset($conLote[$producto->id]);
            $vence = $lote ? DatosLote::vencimiento($linea['vencimiento'] ?? '') : null;
            $vencido = DatosLote::vencido($vence);

            if ($vencido) {
                // § 5.1.4: se puede ingresar, pero queda bloqueado para la venta; se avisa antes de confirmar.
                $vencidos[] = $producto->nombre.' · '.__('lote :lote', ['lote' => trim($linea['lote'] ?? '')]).' · '.DatosLote::formatear($vence);
            }

            $cantidad = Decimal::normalizar($linea['cantidad'], Decimal::ESCALA_CANTIDAD);
            $costo = Decimal::normalizar($linea['costo'] ?? '', Decimal::ESCALA_DINERO);
            $subtotal = $cantidad === null || $costo === null || Decimal::comparar($cantidad, '0') <= 0 || Decimal::comparar($costo, '0', 2) < 0
                ? null : CalculadoraCompra::subtotal($cantidad, $costo);
            $valido = $valido && $subtotal !== null;
            $unidades = $cantidad === null ? $unidades : Decimal::sumar($unidades, $cantidad);

            if ($cantidad !== null && Decimal::comparar($cantidad, '0') > 0) {
                $comprado[$producto->id] = Decimal::sumar($comprado[$producto->id] ?? '0', $cantidad);
            }

            $total = $subtotal === null ? $total : Decimal::sumar($total, $subtotal, 2);
            $filas[] = compact('indice', 'clave', 'producto', 'cantidad', 'costo', 'subtotal', 'lote', 'vence', 'vencido')
                + ['numeroLote' => trim($linea['lote'] ?? '')];
        }

        foreach ($comprado as $productoId => $cantidad) {
            $producto = $datos['productos']->get($productoId);

            if ($sucursalId === null || $producto?->stock_maximo === null) {
                continue;
            }

            $despues = Decimal::sumar($datos['stock'][$productoId] ?? '0', $cantidad);

            if (Decimal::comparar($despues, (string) $producto->stock_maximo) > 0) {
                $sobreMaximo[] = __(':producto: quedará en :despues (máximo :maximo)', [
                    'producto' => $producto->nombre, 'despues' => Decimal::formatear($despues), 'maximo' => Decimal::formatear((string) $producto->stock_maximo),
                ]);
            }
        }

        $mixto = Decimal::sumar(
            Decimal::normalizar($this->efectivo === '' ? '0' : $this->efectivo, 2) ?? '0',
            Decimal::normalizar($this->electronico === '' ? '0' : $this->electronico, 2) ?? '0',
            Decimal::ESCALA_DINERO,
        );

        return view('livewire.compras.nueva-compra', [
            'sinPrecio' => $this->productosSinPrecio(),
            'sucursales' => Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre']),
            'proveedores' => Proveedor::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'filas' => $filas,
            'vencidos' => $vencidos,
            'sobreMaximo' => $sobreMaximo,
            'unidades' => $unidades,
            'total' => $total,
            'valido' => $valido,
            'mixto' => $mixto,
            'aPagar' => match ($this->condicion) {
                'contado' => $total,
                'parcial' => Decimal::normalizar($this->pagado, 2) ?? '0.00',
                default => '0.00',
            },
        ]);
    }
}
