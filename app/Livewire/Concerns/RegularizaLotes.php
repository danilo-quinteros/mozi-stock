<?php

namespace App\Livewire\Concerns;

use App\Domain\Inventario\Actions\RegularizarLote;
use App\Domain\Inventario\DatosLote;
use App\Models\OperacionInventario;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * Regularizar el stock «Sin lote» de un producto en una sucursal (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 9.3): pasarlo
 * a uno o más lotes reales con su número y vencimiento (RegularizarLote, un ajuste con nota obligatoria). Lo usan
 * Vencimientos y la trazabilidad del lote, con el mismo modal (inventario/partials/regularizar-lote).
 *
 * Lo que llega del navegador solo elige sucursal y producto: RegularizarLote vuelve a validar empresa, sucursal,
 * producto y saldo con bloqueo. El componente aporta actor() y avisar() (MuestraAvisos).
 */
trait RegularizaLotes
{
    /** Regularización abierta: sucursal y producto del stock «Sin fecha» (los fija el servidor al abrirla). */
    #[Locked]
    public ?int $regSucursalId = null;

    #[Locked]
    public ?int $regProductoId = null;

    #[Locked]
    public string $regClave = '';

    /** @var list<array{lote: string, vencimiento: string, cantidad: string}> */
    public array $regLineas = [];

    public string $regNota = '';

    public function regularizar(int $sucursalId, int $productoId): void
    {
        $this->authorize('create', OperacionInventario::class);

        if (! Sucursal::query()->whereKey($sucursalId)->exists() || ! Producto::query()->whereKey($productoId)->where('controla_vencimiento', true)->exists()) {
            return;
        }

        $this->resetValidation();
        $this->regSucursalId = $sucursalId;
        $this->regProductoId = $productoId;
        $this->regClave = (string) Str::uuid();
        $this->regLineas = [['lote' => '', 'vencimiento' => '', 'cantidad' => Decimal::simple($this->saldoSinFecha())]];
        $this->regNota = '';
    }

    public function otroLote(): void
    {
        if ($this->regProductoId !== null && count($this->regLineas) < 20) {
            $this->regLineas[] = ['lote' => '', 'vencimiento' => '', 'cantidad' => ''];
        }
    }

    public function quitarLinea(int $indice): void
    {
        if (count($this->regLineas) > 1 && isset($this->regLineas[$indice])) {
            $lineas = $this->regLineas;
            unset($lineas[$indice]);
            $this->regLineas = array_values($lineas);
        }
    }

    public function cerrarRegularizacion(): void
    {
        $this->regSucursalId = null;
        $this->regProductoId = null;
        $this->regLineas = [];
        $this->resetValidation();
    }

    public function confirmarRegularizacion(RegularizarLote $accion): void
    {
        if ($this->regSucursalId === null || $this->regProductoId === null) {
            return;
        }

        $this->validate(['regNota' => ['required', 'string', 'max:250']], [], ['regNota' => __('nota')]);

        $operacion = $accion->ejecutar($this->actor(), [
            'sucursal_id' => $this->regSucursalId,
            'producto_id' => $this->regProductoId,
            'nota' => $this->regNota,
            'lineas' => $this->regLineas,
            'clave' => $this->regClave,
        ]);

        $this->cerrarRegularizacion();
        $this->avisar(__(':codigo registrado: el stock quedó en sus lotes.', ['codigo' => $operacion->codigo()]));
    }

    /**
     * Datos del modal abierto, o null si no hay regularización abierta.
     *
     * @return array{producto: string, sucursal: string, disponible: string, vencidos: list<string>}|null
     */
    protected function regularizando(): ?array
    {
        if ($this->regProductoId === null || $this->regSucursalId === null) {
            return null;
        }

        $producto = Producto::query()->with('unidad:id,abreviatura')->find($this->regProductoId);

        return [
            'producto' => $producto === null ? '' : $producto->nombre,
            'sucursal' => (string) Sucursal::query()->whereKey($this->regSucursalId)->value('nombre'),
            'disponible' => $this->saldoSinFecha(),
            'vencidos' => array_values(array_filter(array_map(function (array $linea): ?string {
                $vence = DatosLote::vencimiento($linea['vencimiento']);

                return DatosLote::vencido($vence) ? trim($linea['lote']).' · '.DatosLote::formatear($vence) : null;
            }, $this->regLineas))),
        ];
    }

    /**
     * Saldo actual del lote de regularización en la sucursal elegida (solo informa; la acción lo valida bloqueado).
     */
    private function saldoSinFecha(): string
    {
        $empresaId = (int) $this->actor()->empresa_id;

        return (string) (DB::table('stock_lotes as sl')
            ->join('lotes as l', 'l.id', '=', 'sl.lote_id')
            ->where('sl.empresa_id', $empresaId)
            ->where('l.empresa_id', $empresaId)
            ->where('sl.sucursal_id', $this->regSucursalId)
            ->where('sl.producto_id', $this->regProductoId)
            ->where('l.es_regularizacion', true)
            ->value('sl.cantidad') ?? '0');
    }
}
