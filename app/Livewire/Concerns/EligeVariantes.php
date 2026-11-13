<?php

namespace App\Livewire\Concerns;

use App\Domain\Catalogo\Consultas\SelectorVariantes;
use App\Enums\Capacidad;
use App\Models\Modelo;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Selector de talla y color (ESPECIFICACION_TALLAS_Y_COLORES.md § 5 y § 7, etapa V2), compartido por las cinco
 * pantallas de captura a través de CapturaProductos:
 *
 * - Pedidos y ventas, y POS (pantallas que venden): botones de color y de talla con el stock vendible de cada
 *   combinación en la sucursal; las que no tienen stock se ven deshabilitadas. Cada botón agrega una unidad con la
 *   misma acción que el buscador (el POS vuelve a revisar el stock).
 * - Compras, ajustes y traspasos: cuadrícula tallas × colores para escribir cantidades (y el costo, en compras).
 *   Al aceptar se convierte en líneas normales, una por combinación; las acciones del servidor no cambian.
 *
 * Se implementa como concern + vista parcial, no como componente anidado: las líneas viven en la pantalla y así no
 * hacen falta eventos de ida y vuelta. Todo lo que se muestra es informativo; la sucursal se resuelve en el servidor
 * (el vendedor siempre ve la suya) y cada acción de dominio vuelve a validar stock, precios y permisos.
 */
trait EligeVariantes
{
    /** Modelo abierto en el selector; solo lo cambia el servidor. */
    #[Locked]
    public ?int $selectorModeloId = null;

    /** Color elegido en el selector de venta (id, o '0' para un modelo sin color). */
    public string $selectorColor = '';

    /**
     * Cantidades de la cuadrícula por celda «talla-color».
     *
     * @var array<string, string>
     */
    public array $selectorCantidades = [];

    /** Costo unitario para todas las combinaciones de la cuadrícula (solo compras); se edita después por línea. */
    public string $selectorCosto = '';

    /**
     * Abre el selector del modelo. Con una sola combinación activa la agrega directamente. Devuelve si el pedido se
     * atendió (el buscador limpia el texto).
     */
    public function abrirSelector(int $modeloId): bool
    {
        $this->cerrarSelector();

        if (! Gate::allows('capacidad', Capacidad::Variantes)) {
            $this->mensajeCaptura = __('El producto no está disponible.');

            return false;
        }

        // El scope de empresa (y el filtro explícito) impide abrir modelos de otra empresa.
        $modelo = Modelo::query()->where('empresa_id', $this->actorSelector()->empresa_id)->where('activo', true)->find($modeloId, ['id', 'nombre']);

        if ($modelo === null) {
            $this->mensajeCaptura = __('El producto no está disponible.');

            return false;
        }

        $datos = $this->datosSelector($modelo->id);

        if ($datos['celdas'] === []) {
            $this->mensajeCaptura = __(':modelo no tiene combinaciones activas.', ['modelo' => $modelo->nombre]);

            return false;
        }

        if (count($datos['celdas']) === 1) {
            return $this->agregarProducto(array_values($datos['celdas'])[0]['id']);
        }

        $this->selectorModeloId = $modelo->id;
        $this->selectorColor = $this->colorInicial($datos);
        // Como al agregar un producto: la búsqueda se vacía y las sugerencias se cierran.
        $this->busqueda = '';

        return true;
    }

    public function cerrarSelector(): void
    {
        $this->selectorModeloId = null;
        $this->selectorColor = '';
        $this->selectorCantidades = [];
        $this->selectorCosto = '';
        $claves = array_values(array_filter(array_keys($this->getErrorBag()->getMessages()), fn (int|string $clave): bool => str_starts_with((string) $clave, 'selector')));

        if ($claves !== []) {
            $this->resetErrorBag($claves);
        }
    }

    public function elegirColorSelector(string $color): void
    {
        if ($this->selectorModeloId === null) {
            return;
        }

        $colores = array_map(fn (array $c): string => (string) ($c['id'] ?? 0), $this->datosSelector($this->selectorModeloId)['colores']);
        $this->selectorColor = in_array($color, $colores, true) ? $color : $this->selectorColor;
    }

    /**
     * Venta: agrega una unidad de la combinación elegida. El selector sigue abierto para elegir otra talla.
     */
    public function elegirVariante(int $productoId): void
    {
        if ($this->selectorModeloId === null || $this->selectorConCuadricula()) {
            return;
        }

        $celda = collect($this->datosSelector($this->selectorModeloId)['celdas'])->firstWhere('id', $productoId);

        if ($celda === null) {
            $this->mensajeCaptura = __('El producto no está disponible.');

            return;
        }

        if (! $celda['disponible']) {
            $this->mensajeCaptura = __('No hay existencias de :producto en esta sucursal.', ['producto' => $celda['nombre']]);

            return;
        }

        $this->agregarProducto($productoId);
    }

    /**
     * Compras, ajustes y traspasos: convierte la cuadrícula en líneas normales (una por combinación con cantidad).
     */
    public function agregarCuadricula(): void
    {
        if ($this->selectorModeloId === null || ! $this->selectorConCuadricula()) {
            return;
        }

        $modelo = Modelo::query()->with('unidad:id,admite_decimales')->find($this->selectorModeloId, ['id', 'unidad_medida_id']);
        $celdas = $this->datosSelector($this->selectorModeloId)['celdas'];
        $enteras = $modelo !== null && ! (bool) $modelo->unidad->admite_decimales;
        $errores = [];
        $cantidades = [];

        foreach ($this->selectorCantidades as $clave => $texto) {
            $texto = trim((string) $texto);
            $celda = $celdas[$clave] ?? null;

            if ($texto === '' || $celda === null || ! $celda['controla_stock']) {
                continue;
            }

            $cantidad = Decimal::normalizar($texto, Decimal::ESCALA_CANTIDAD);

            if ($cantidad === null || Decimal::comparar($cantidad, '0') <= 0) {
                $errores["selectorCantidades.{$clave}"] = __('Cantidad inválida');
            } elseif ($enteras && ! Decimal::esEntero($cantidad)) {
                $errores["selectorCantidades.{$clave}"] = __('Usa cantidades enteras.');
            } else {
                $cantidades[$celda['id']] = Decimal::simple($cantidad);
            }
        }

        $texto = trim($this->selectorCosto);
        $costo = null;

        if ($this->selectorPideCosto() && $texto !== '') {
            $normalizado = Decimal::normalizar($texto, Decimal::ESCALA_DINERO);

            if ($normalizado === null || Decimal::comparar($normalizado, '0') < 0) {
                $errores['selectorCosto'] = __('Escribe un costo válido o déjalo en blanco.');
            } else {
                $costo = $texto;
            }
        }

        if ($errores === [] && $cantidades === []) {
            $errores['selector'] = __('Escribe la cantidad de al menos una combinación.');
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        foreach ($cantidades as $productoId => $cantidad) {
            $this->agregarCantidad($productoId, $cantidad, $costo);
        }

        $this->cerrarSelector();
        $this->mensajeCaptura = trans_choice(':count combinación agregada a la lista.|:count combinaciones agregadas a la lista.', count($cantidades));
    }

    /**
     * Datos del selector abierto para la vista; null si está cerrado.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function selector(): ?array
    {
        if ($this->selectorModeloId === null) {
            return null;
        }

        $modelo = Modelo::query()->with('unidad:id,abreviatura')->find($this->selectorModeloId, ['id', 'nombre', 'precio_consumidor', 'unidad_medida_id', 'imagen_path']);

        if ($modelo === null) {
            return null;
        }

        $datos = $this->datosSelector($modelo->id);
        $conTallas = collect($datos['tallas'])->contains(fn (array $t): bool => $t['id'] !== null);
        $conColores = collect($datos['colores'])->contains(fn (array $c): bool => $c['id'] !== null);

        return $datos + [
            'modelo' => $modelo,
            'cuadricula' => $this->selectorConCuadricula(),
            'costo' => $this->selectorPideCosto(),
            'conTallas' => $conTallas,
            'conColores' => $conColores,
            'sucursal' => $this->sucursalSelector() !== null,
        ];
    }

    /**
     * Pantallas que venden (admiten productos sin control de stock) eligen con botones; las de inventario, con
     * cuadrícula de cantidades.
     */
    protected function selectorConCuadricula(): bool
    {
        return ! $this->admiteSinControlDeStock();
    }

    /** Stock que muestra el selector: vendible (sin vencidas) al vender y traspasar; total en compras y ajustes. */
    protected function stockVendibleEnSelector(): bool
    {
        return $this->admiteSinControlDeStock();
    }

    /** Compras: la línea tiene costo, y la cuadrícula ofrece uno para todas. */
    protected function selectorPideCosto(): bool
    {
        return array_key_exists('costo', $this->nuevaLinea(0));
    }

    /**
     * @return array{tallas: list<array{id: int|null, nombre: string}>, colores: list<array{id: int|null, nombre: string, muestra: string|null}>, celdas: array<string, array{id: int, nombre: string, sku: string|null, stock: string, controla_stock: bool, disponible: bool, precio_consumidor: string}>}
     */
    private function datosSelector(int $modeloId): array
    {
        return app(SelectorVariantes::class)->delModelo((int) $this->actorSelector()->empresa_id, $modeloId,
            $this->sucursalSelector(), $this->stockVendibleEnSelector());
    }

    /**
     * Sucursal cuyo stock se muestra: la del vendedor siempre; la de la pantalla (su propiedad `sucursal_id`, el origen
     * en traspasos) si es de la empresa.
     */
    private function sucursalSelector(): ?int
    {
        $actor = $this->actorSelector();

        if ($actor->esVendedor()) {
            return $actor->sucursal_id === null ? null : (int) $actor->sucursal_id;
        }

        $id = (string) $this->sucursal_id;

        return ctype_digit($id) && Sucursal::query()->where('empresa_id', $actor->empresa_id)->whereKey((int) $id)->exists() ? (int) $id : null;
    }

    /**
     * Primer color con alguna combinación disponible (al vender) o el primero.
     *
     * @param  array{colores: list<array{id: int|null}>, celdas: array<string, array{disponible: bool}>}  $datos
     */
    private function colorInicial(array $datos): string
    {
        foreach ($datos['colores'] as $color) {
            $id = (string) ($color['id'] ?? 0);

            foreach ($datos['celdas'] as $clave => $celda) {
                if (str_ends_with($clave, '-'.$id) && ($celda['disponible'] || $this->selectorConCuadricula())) {
                    return $id;
                }
            }
        }

        return (string) ($datos['colores'][0]['id'] ?? 0);
    }

    private function actorSelector(): User
    {
        /** @var User */
        return Auth::user();
    }
}
