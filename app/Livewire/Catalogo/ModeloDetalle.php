<?php

namespace App\Livewire\Catalogo;

use App\Domain\Catalogo\Actions\CambiarPrecioVariante;
use App\Domain\Catalogo\Actions\QuitarDeModelo;
use App\Domain\Catalogo\Consultas\CuadriculaStock;
use App\Domain\Catalogo\Consultas\ListadoProductos;
use App\Enums\Capacidad;
use App\Livewire\Concerns\ExigeCapacidad;
use App\Livewire\Concerns\MuestraAvisos;
use App\Livewire\Concerns\UsaSucursalActiva;
use App\Models\Modelo;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Detalle del modelo (ESPECIFICACION_TALLAS_Y_COLORES.md § 4.4 y § 5): cuadrícula de stock tallas × colores por
 * sucursal (una consulta, con el semáforo de siempre) y la lista de combinaciones, donde el administrador cambia el
 * precio de una combinación a precio propio o la devuelve al precio del modelo, o la quita del modelo (§ 9, vuelve a
 * ser un producto suelto). El vendedor ve el stock de su sucursal, como en el catálogo.
 */
class ModeloDetalle extends Component
{
    use ExigeCapacidad, MuestraAvisos, UsaSucursalActiva;

    private const PRECIO = 'regex:/^\d{1,15}([.,]\d{1,2})?$/';

    #[Locked]
    public int $modeloId;

    #[Url(except: '')]
    public string $sucursal = '';

    public bool $formPrecio = false;

    #[Locked]
    public ?int $varianteId = null;

    public string $precioConsumidor = '';

    public ?string $precioMinorista = '';

    public ?string $precioMayorista = '';

    public function mount(Modelo $modelo): void
    {
        $this->exigirCapacidad(Capacidad::Variantes);
        $this->authorize('view', $modelo);
        $this->modeloId = $modelo->id;
        $this->aplicarSucursalActiva();

        if (is_string($mensaje = session('aviso'))) {
            $this->avisar($mensaje);
        }
    }

    public function cambiarPrecio(int $id): void
    {
        $variante = $this->variante($id);
        $this->authorize('update', $variante);
        $this->resetValidation();
        $this->varianteId = $variante->id;
        $this->precioConsumidor = Decimal::simple($variante->precio_consumidor);
        $this->precioMinorista = $variante->precio_minorista === null ? '' : Decimal::simple($variante->precio_minorista);
        $this->precioMayorista = $variante->precio_mayorista === null ? '' : Decimal::simple($variante->precio_mayorista);
        $this->formPrecio = true;
    }

    public function guardarPrecio(CambiarPrecioVariante $accion): void
    {
        $this->exigirCapacidad(Capacidad::Variantes);

        if ($this->varianteId === null) {
            return;
        }

        $this->validate([
            'precioConsumidor' => ['required', self::PRECIO],
            'precioMinorista' => ['nullable', self::PRECIO],
            'precioMayorista' => ['nullable', self::PRECIO],
        ], [
            'precioConsumidor.regex' => __('Ingresa un precio válido (por ejemplo 13,00).'),
            'precioMinorista.regex' => __('Ingresa un precio válido (por ejemplo 11,10).'),
            'precioMayorista.regex' => __('Ingresa un precio válido (por ejemplo 9,80).'),
        ], ['precioConsumidor' => __('precio consumidor'), 'precioMinorista' => __('precio minorista'), 'precioMayorista' => __('precio mayorista')]);

        $variante = $accion->ejecutar($this->actor(), $this->variante($this->varianteId), [
            'precio_consumidor' => $this->precioConsumidor,
            'precio_minorista' => $this->precioMinorista,
            'precio_mayorista' => $this->precioMayorista,
        ]);

        $this->formPrecio = false;
        $this->varianteId = null;
        $this->avisar(__(':nombre tiene ahora precio propio: :precio.', ['nombre' => $variante->nombre, 'precio' => Decimal::bs($variante->precio_consumidor)]));
    }

    public function usarPrecioModelo(int $id, CambiarPrecioVariante $accion): void
    {
        $this->exigirCapacidad(Capacidad::Variantes);
        $variante = $accion->ejecutar($this->actor(), $this->variante($id), null);
        $this->formPrecio = false;
        $this->varianteId = null;
        $this->avisar(__(':nombre vuelve a usar el precio del modelo: :precio.', ['nombre' => $variante->nombre, 'precio' => Decimal::bs($variante->precio_consumidor)]));
    }

    /** § 9 (deshacer): la combinación vuelve a ser un producto suelto, con su stock y su historial. */
    public function quitarDelModelo(int $id, QuitarDeModelo $accion): void
    {
        $this->exigirCapacidad(Capacidad::Variantes);
        $producto = $accion->ejecutar($this->actor(), $this->variante($id));
        $this->formPrecio = false;
        $this->varianteId = null;
        $this->avisar(__(':nombre es ahora un producto suelto. Conserva su stock y su historial.', ['nombre' => $producto->nombre]));
    }

    public function cerrar(): void
    {
        $this->formPrecio = false;
        $this->varianteId = null;
        $this->resetValidation();
    }

    public function render(): View
    {
        $actor = $this->actor();
        $modelo = Modelo::query()->with(['categoria:id,nombre', 'unidad:id,nombre,abreviatura', 'grupoTalla:id,nombre'])->findOrFail($this->modeloId);
        $sucursal = ListadoProductos::sucursalParaVista($actor, $this->sucursal);
        $cuadricula = CuadriculaStock::delModelo((int) $actor->empresa_id, $modelo->id, $sucursal?->id);
        $celdas = collect($cuadricula['celdas']);

        return view('livewire.catalogo.modelo-detalle', [
            'modelo' => $modelo,
            'cuadricula' => $cuadricula,
            'activas' => $celdas->where('activo', true)->count(),
            'conPrecioPropio' => $celdas->where('activo', true)->where('precio_propio', true)->count(),
            'alertasStock' => $celdas->where('activo', true)->whereIn('alerta', ['sin_stock', 'bajo'])->count(),
            'sucursalVista' => $sucursal,
            'sucursales' => $actor->esAdministrador() ? Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre']) : collect(),
            'administra' => $actor->can('update', $modelo),
            'varianteEditada' => $this->varianteId === null ? null : $celdas->firstWhere('id', $this->varianteId),
        ])->title($modelo->nombre);
    }

    /** Combinación de este modelo (el scope de empresa da 404 a las de otra empresa). */
    private function variante(int $id): Producto
    {
        return Producto::query()->where('modelo_id', $this->modeloId)->findOrFail($id);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
