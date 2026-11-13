<?php

namespace App\Livewire\Concerns;

use App\Domain\Despachos\Actions\CancelarEntregaDevuelta;
use App\Domain\Despachos\Actions\CorregirEstadoEntrega;
use App\Domain\Despachos\Actions\DeshacerEntrega;
use App\Domain\Despachos\Actions\DespacharVenta;
use App\Domain\Despachos\Actions\MarcarEntregada;
use App\Domain\Ventas\Actions\RegistrarDevolucion;
use App\Enums\EstadoEntrega;
use App\Models\User;
use App\Models\Venta;
use App\Support\Decimal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Diálogos de entrega (ESPECIFICACION_DESPACHOS.md § 4): Despachar, Despachar y entregar, Marcar entregado y,
 * para el administrador, deshacer el último cambio o cancelar una venta despachada devuelta. Lo usan la
 * pestaña Despachos y el detalle de venta. La venta se vuelve a buscar con el alcance de la empresa y las
 * acciones autorizan, bloquean y comprueban el estado: este trait solo recoge los textos opcionales.
 *
 * Cocina (ESPECIFICACION_COCINA.md § 4.3): «Entregar» un pedido En cocina con un solo clic, sin diálogo, y el
 * aviso con «Deshacer» durante 10 segundos. Lo usan el detalle de venta y el panel En cocina (Cocina\Panel).
 *
 * Requiere MuestraAvisos en el componente.
 */
trait GestionaEntregas
{
    /** @var list<string> */
    private const DIALOGOS_ENTREGA = ['despachar', 'despachar_entregar', 'entregar', 'corregir', 'cancelar'];

    #[Locked]
    public ?int $entregaVentaId = null;

    #[Locked]
    public string $entregaDialogo = '';

    /** Estado que el administrador vio al abrir la corrección: si cambió mientras tanto, la acción se rechaza. */
    #[Locked]
    public string $entregaDesde = '';

    public string $entregaRetira = '';

    public string $entregaRecibio = '';

    public string $entregaNota = '';

    /** Pedido recién entregado con un clic: su aviso ofrece «Deshacer» mientras dura el plazo (cocina § 4.3). */
    #[Locked]
    public ?int $deshacerVentaId = null;

    public function abrirEntrega(int $ventaId, string $dialogo): void
    {
        abort_unless(in_array($dialogo, self::DIALOGOS_ENTREGA, true), 404);
        $venta = Venta::query()->findOrFail($ventaId);
        $this->authorize(self::permisoEntrega($dialogo), $venta);

        $this->resetValidation();
        $this->reset('entregaRetira', 'entregaRecibio', 'entregaNota');
        $this->entregaVentaId = $venta->id;
        $this->entregaDialogo = $dialogo;
        $this->entregaDesde = $venta->estado_entrega->value;
    }

    public function cerrarEntrega(): void
    {
        $this->resetValidation();
        $this->reset('entregaVentaId', 'entregaDialogo', 'entregaDesde', 'entregaRetira', 'entregaRecibio', 'entregaNota');
    }

    public function confirmarEntrega(DespacharVenta $despachar, MarcarEntregada $entregar, CorregirEstadoEntrega $corregir,
        CancelarEntregaDevuelta $cancelar): void
    {
        // Segundo clic de un doble envío cuando el primero ya cerró el diálogo: no hay nada que repetir.
        if ($this->entregaVentaId === null || ! in_array($this->entregaDialogo, self::DIALOGOS_ENTREGA, true)) {
            return;
        }

        /** @var User $actor */
        $actor = Auth::user();
        $venta = Venta::query()->findOrFail($this->entregaVentaId);
        $dialogo = $this->entregaDialogo;

        try {
            $venta = match ($dialogo) {
                'despachar' => $despachar->ejecutar($actor, $venta, $this->entregaRetira),
                'despachar_entregar' => $entregar->ejecutar($actor, $venta, $this->entregaRecibio, $this->entregaNota, despacharTambien: true),
                'entregar' => $entregar->ejecutar($actor, $venta, $this->entregaRecibio, $this->entregaNota),
                'corregir' => $corregir->ejecutar($actor, $venta, EstadoEntrega::from($this->entregaDesde), $this->entregaNota),
                default => $cancelar->ejecutar($actor, $venta, $this->entregaNota),
            };
        } catch (ValidationException $e) {
            // La venta cambió de estado (otro usuario, doble clic, devolución): el diálogo ya no sirve.
            if (isset($e->errors()['estado_entrega'])) {
                $this->cerrarEntrega();
                $this->avisar($e->errors()['estado_entrega'][0], 'danger');

                return;
            }

            throw $e;
        }

        $this->cerrarEntrega();
        $this->avisar(match ($dialogo) {
            'despachar' => __('Venta :codigo despachada.', ['codigo' => $venta->codigo()]),
            'despachar_entregar', 'entregar' => __('Venta :codigo entregada.', ['codigo' => $venta->codigo()]),
            'corregir' => __('Cambio deshecho: la venta :codigo quedó «:estado».', ['codigo' => $venta->codigo(), 'estado' => $venta->estado_entrega->etiqueta()]),
            default => __('Venta :codigo cancelada.', ['codigo' => $venta->codigo()]),
        });
        $this->entregaActualizada();
    }

    /**
     * «Entregar» un pedido En cocina con un clic (cocina § 4.3). Si otro usuario lo entregó antes, o una
     * devolución lo canceló, se muestra el motivo («Ya entregado por … a las …») y no se registra nada.
     */
    public function entregarPedido(int $ventaId): void
    {
        $venta = Venta::query()->findOrFail($ventaId);

        try {
            app(MarcarEntregada::class)->ejecutar($this->actorEntrega(), $venta);
        } catch (ValidationException $e) {
            if (! isset($e->errors()['estado_entrega'])) {
                throw $e;
            }

            $this->avisar($e->errors()['estado_entrega'][0], 'danger');
            $this->entregaActualizada();

            return;
        }

        $this->deshacerVentaId = $venta->id;
        $this->entregaActualizada();
    }

    /**
     * «Deshacer» del aviso: vuelve a En cocina si lo pide quien entregó, dentro de los 10 segundos del servidor.
     */
    public function deshacerEntregaPedido(int $ventaId): void
    {
        $venta = Venta::query()->findOrFail($ventaId);
        $this->deshacerVentaId = null;

        try {
            $venta = app(DeshacerEntrega::class)->ejecutar($this->actorEntrega(), $venta);
        } catch (ValidationException $e) {
            if (! isset($e->errors()['estado_entrega'])) {
                throw $e;
            }

            $this->avisar($e->errors()['estado_entrega'][0], 'danger');
            $this->entregaActualizada();

            return;
        }

        $this->avisar(__(':pedido volvió a cocina.', ['pedido' => self::nombrePedido($venta)]));
        $this->entregaActualizada();
    }

    /**
     * Gancho para el componente (por ejemplo, refrescar datos derivados). Por defecto no hace nada.
     */
    protected function entregaActualizada(): void {}

    /**
     * Aviso «Mesa 4 entregado · Deshacer» mientras quien entregó todavía puede deshacerlo.
     *
     * @return array{venta_id: int, texto: string, segundos: int}|null
     */
    protected function avisoDeshacer(): ?array
    {
        if ($this->deshacerVentaId === null) {
            return null;
        }

        $actor = $this->actorEntrega();
        $venta = Venta::query()->find($this->deshacerVentaId);

        if ($venta === null || ! DeshacerEntrega::disponible($actor, $venta)) {
            return null;
        }

        return [
            'venta_id' => $venta->id,
            'texto' => __(':pedido entregado', ['pedido' => self::nombrePedido($venta)]),
            'segundos' => DeshacerEntrega::segundosRestantes($venta),
        ];
    }

    /**
     * Cómo se reconoce el pedido: el nombre o la mesa y, si está vacío, el número de venta (cocina § 4.3).
     */
    protected static function nombrePedido(Venta $venta): string
    {
        return $venta->nombre_mesa ?? $venta->codigo();
    }

    private function actorEntrega(): User
    {
        /** @var User */
        return Auth::user();
    }

    /**
     * Datos del diálogo abierto: la venta y, para despachar, lo que queda por entregar después de devoluciones.
     *
     * @return array{venta: Venta, lineas: list<array{nombre: string, unidad: string, cantidad: string}>, destino?: EstadoEntrega|null}|null
     */
    protected function dialogoEntrega(): ?array
    {
        if ($this->entregaVentaId === null) {
            return null;
        }

        $venta = Venta::query()->with(['cliente:id,nombre', 'items:id,venta_id,orden,descripcion,sku,unidad,cantidad'])->find($this->entregaVentaId);

        if ($venta === null) {
            return null;
        }

        if ($this->entregaDialogo === 'corregir') {
            return ['venta' => $venta, 'lineas' => [], 'destino' => app(CorregirEstadoEntrega::class)->destino($venta)];
        }

        $devuelto = RegistrarDevolucion::devueltoPorLinea($venta->empresa_id, array_values($venta->items->pluck('id')->all()));
        $lineas = [];

        foreach ($venta->items as $item) {
            $pendiente = Decimal::restar((string) $item->cantidad, $devuelto[$item->id] ?? '0', 3);

            if (Decimal::comparar($pendiente, '0', 3) > 0) {
                $lineas[] = ['nombre' => $item->descripcion, 'unidad' => $item->unidad, 'cantidad' => $pendiente];
            }
        }

        return ['venta' => $venta, 'lineas' => $lineas];
    }

    private static function permisoEntrega(string $dialogo): string
    {
        return match ($dialogo) {
            'despachar', 'despachar_entregar' => 'despachar',
            'entregar' => 'marcarEntregada',
            default => 'corregirEntrega',
        };
    }
}
