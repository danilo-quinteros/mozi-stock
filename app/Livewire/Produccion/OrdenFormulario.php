<?php

namespace App\Livewire\Produccion;

use App\Domain\Inventario\DatosLote;
use App\Domain\Produccion\Actions\EmitirOrden;
use App\Domain\Produccion\Consultas\DisponibilidadMaterias;
use App\Domain\Produccion\LineasOrden;
use App\Enums\EstadoOrdenProduccion;
use App\Models\OrdenProduccion;
use App\Models\Producto;
use App\Models\Receta;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Nueva orden de producción o edición de una Emitida (ESPECIFICACION_PRODUCCION.md § 6.1, § 6.2 y M2). Muestra, solo
 * como aviso, las materias primas necesarias y lo que falta en la sucursal (PR7). Toda la regla vive en EmitirOrden.
 */
#[Title('Orden de producción')]
class OrdenFormulario extends Component
{
    /** Orden que se edita; null al emitir. */
    #[Locked]
    public ?int $ordenId = null;

    /** Clave de idempotencia de esta emisión: un doble clic o reintento devuelve la misma orden. */
    #[Locked]
    public string $clave = '';

    public ?string $producto_id = null;

    public ?string $sucursal_id = null;

    public string $cantidad = '';

    public string $fecha_programada = '';

    public string $nota = '';

    public function mount(?OrdenProduccion $orden = null): void
    {
        $this->authorize('usar-produccion');
        $this->clave = (string) Str::uuid();

        if ($orden !== null && $orden->exists) {
            abort_unless($orden->estado === EstadoOrdenProduccion::Emitida, 404);
            $this->ordenId = $orden->id;
            $this->producto_id = (string) $orden->producto_id;
            $this->sucursal_id = (string) $orden->sucursal_id;
            $this->cantidad = Decimal::simple((string) $orden->cantidad_planificada);
            $this->fecha_programada = $orden->fecha_programada->format('Y-m-d');
            $this->nota = (string) ($orden->nota ?? '');

            return;
        }

        $sucursal = $this->actor()->sucursalActivaId() ?? Sucursal::query()->where('activa', true)->orderBy('nombre')->value('id');
        $this->sucursal_id = $sucursal === null ? null : (string) $sucursal;
        $this->fecha_programada = DatosLote::hoy();
        $producto = request()->query('producto');
        $this->producto_id = is_string($producto) && ctype_digit($producto) ? $producto : null;
    }

    public function guardar(EmitirOrden $accion): void
    {
        $this->resetErrorBag();
        $datos = [
            'sucursal_id' => $this->sucursal_id,
            'cantidad' => $this->cantidad,
            'fecha_programada' => $this->fecha_programada,
            'nota' => $this->nota,
        ];

        if ($this->ordenId === null) {
            $orden = $accion->emitir($this->actor(), $datos + ['producto_id' => $this->producto_id, 'clave' => $this->clave]);
            session()->flash('aviso', __('Orden :codigo emitida. Apruébala cuando vayas a producir: recién entonces se descuentan las materias primas.', ['codigo' => $orden->codigo()]));
        } else {
            $orden = $accion->editar($this->actor(), OrdenProduccion::query()->findOrFail($this->ordenId), $datos);
            session()->flash('aviso', __('Orden :codigo guardada.', ['codigo' => $orden->codigo()]));
        }

        $this->redirectRoute('produccion.ordenes.ver', ['orden' => $orden->id], navigate: false);
    }

    public function render(): View
    {
        $orden = $this->ordenId === null ? null : OrdenProduccion::query()->with('producto.unidad:id,abreviatura')->findOrFail($this->ordenId);

        return view('livewire.produccion.orden-formulario', [
            'orden' => $orden,
            'productos' => $orden === null ? $this->producibles() : collect(),
            'sucursales' => Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre']),
            'vista' => $this->vistaPrevia($orden),
        ])->title($orden === null ? __('Nueva orden de producción') : __('Editar orden :codigo', ['codigo' => $orden->codigo()]));
    }

    /**
     * Productos con receta vigente y activa que se pueden fabricar (§ 5.1): una consulta.
     *
     * @return Collection<int, Producto>
     */
    private function producibles(): Collection
    {
        return Producto::query()
            ->join('recetas', fn ($j) => $j->on('recetas.producto_id', '=', 'productos.id')->whereColumn('recetas.empresa_id', 'productos.empresa_id'))
            ->whereNotNull('recetas.producto_vigente')
            ->where('recetas.activa', true)
            ->where('productos.clase', Producto::CLASE_VENTA)
            ->where('productos.activo', true)
            ->where('productos.controla_stock', true)
            ->whereNull('productos.modelo_id')
            ->with('unidad:id,abreviatura')
            ->orderBy('productos.nombre')
            ->get(['productos.id', 'productos.nombre', 'productos.contenido', 'productos.sku', 'productos.unidad_medida_id']);
    }

    /**
     * Materias primas necesarias para la cantidad escrita y faltantes en la sucursal. Al emitir usa la receta vigente;
     * al editar, la copia que guardó la orden (M2). Una cantidad inválida no muestra cantidades.
     *
     * @return array{producto: Producto|null, receta: Receta|null, lineas: list<array{producto_id: int, nombre: string, unidad: string, cantidad_receta: string, necesaria: string|null, disponible: string|null, falta: string|null}>, base: string|null}
     */
    private function vistaPrevia(?OrdenProduccion $orden): array
    {
        $empresaId = (int) $this->actor()->empresa_id;
        $producto = match (true) {
            $orden !== null => $orden->producto,
            $this->producto_id !== null && ctype_digit($this->producto_id) => Producto::query()->with('unidad:id,abreviatura')->find((int) $this->producto_id),
            default => null,
        };
        $receta = null;

        if ($orden !== null) {
            $lineas = LineasOrden::deOrden($empresaId, $orden->id);
            $base = (string) $orden->cantidad_base;
        } elseif ($producto !== null && ($receta = Receta::query()->where('producto_id', $producto->id)->where('vigente', true)->first()) !== null) {
            $lineas = LineasOrden::deReceta($empresaId, $receta->id);
            $base = (string) $receta->cantidad_base;
        } else {
            return ['producto' => $producto, 'receta' => null, 'lineas' => [], 'base' => null];
        }

        $cantidad = Decimal::normalizar($this->cantidad, Decimal::ESCALA_CANTIDAD);
        $teoricas = $cantidad !== null && Decimal::comparar($cantidad, '0') > 0 ? LineasOrden::escalar($lineas, $cantidad, $base) : [];
        $sucursalId = $this->sucursal_id !== null && ctype_digit($this->sucursal_id) ? (int) $this->sucursal_id : null;
        $faltantes = $teoricas === [] || $sucursalId === null ? [] : app(DisponibilidadMaterias::class)->faltantes($empresaId, $sucursalId, $teoricas);

        return [
            'producto' => $producto,
            'receta' => $receta,
            'base' => $base,
            'lineas' => array_map(fn (array $l): array => [
                'producto_id' => $l['producto_id'], 'nombre' => $l['nombre'], 'unidad' => $l['unidad'], 'cantidad_receta' => $l['cantidad_receta'],
                'necesaria' => $teoricas[$l['producto_id']] ?? null,
                'disponible' => $faltantes[$l['producto_id']]['disponible'] ?? null,
                'falta' => $faltantes[$l['producto_id']]['falta'] ?? null,
            ], $lineas),
        ];
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
