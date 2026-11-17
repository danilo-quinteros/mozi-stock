<?php

namespace App\Livewire\Produccion;

use App\Domain\Produccion\Actions\AnularOrden;
use App\Domain\Produccion\Actions\AprobarOrden;
use App\Domain\Produccion\Consultas\DisponibilidadMaterias;
use App\Domain\Produccion\Escalado;
use App\Domain\Produccion\LineasOrden;
use App\Enums\EstadoOrdenProduccion;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\OrdenProduccion;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use stdClass;

/**
 * Detalle de una orden de producción (ESPECIFICACION_PRODUCCION.md § 6): datos, materias primas (teórico, real, costo
 * y lotes usados), operaciones de inventario y acciones según el estado: editar y aprobar (Emitida), registrar lo
 * producido (Aprobada) y anular con motivo. La orden se resuelve con el scope de empresa: la de otra empresa da 404.
 */
#[Title('Orden de producción')]
class OrdenDetalle extends Component
{
    use MuestraAvisos;

    #[Locked]
    public int $ordenId;

    public bool $aprobando = false;

    public bool $anulando = false;

    public string $motivo = '';

    public function mount(OrdenProduccion $orden): void
    {
        $this->authorize('usar-produccion');
        $this->ordenId = $orden->id;

        if (is_string($mensaje = session('aviso'))) {
            $this->avisar($mensaje);
        }
    }

    public function aprobar(AprobarOrden $accion): void
    {
        $this->resetErrorBag();
        $orden = $accion->ejecutar($this->actor(), $this->orden());
        $this->aprobando = false;
        $this->avisar(__('Orden :codigo aprobada: se descontaron las materias primas.', ['codigo' => $orden->codigo()]));
    }

    public function anular(AnularOrden $accion): void
    {
        $this->resetErrorBag();
        $orden = $accion->ejecutar($this->actor(), $this->orden(), $this->motivo);
        $this->anulando = false;
        $this->motivo = '';
        $this->avisar($orden->operacion_anulacion_id !== null
            ? __('Orden :codigo anulada: las materias primas volvieron al almacén.', ['codigo' => $orden->codigo()])
            : __('Orden :codigo anulada.', ['codigo' => $orden->codigo()]));
    }

    public function render(): View
    {
        $orden = $this->orden()->load([
            'producto' => fn ($q) => $q->select(['id', 'empresa_id', 'nombre', 'contenido', 'sku', 'unidad_medida_id'])->with('unidad:id,abreviatura'),
            'sucursal:id,nombre', 'receta:id,version', 'lote:id,numero,vencimiento',
            'emisor:id,name', 'aprobador:id,name', 'finalizador:id,name', 'anulador:id,name',
        ]);
        $empresaId = (int) $orden->empresa_id;
        $lineas = LineasOrden::deOrden($empresaId, $orden->id);
        $faltantes = $orden->estado === EstadoOrdenProduccion::Emitida
            ? app(DisponibilidadMaterias::class)->faltantes($empresaId, $orden->sucursal_id, array_column($lineas, 'cantidad_teorica', 'producto_id'))
            : [];

        return view('livewire.produccion.orden-detalle', [
            'orden' => $orden,
            'lineas' => $lineas,
            'faltantes' => $faltantes,
            'lotes' => $this->lotesUsados($orden),
            'operaciones' => $this->operaciones($orden),
            'variacion' => $orden->cantidad_obtenida === null ? null : Escalado::variacion((string) $orden->cantidad_planificada, (string) $orden->cantidad_obtenida),
        ])->title(__('Orden :codigo', ['codigo' => $orden->codigo()]));
    }

    /**
     * Lotes de materia prima que la orden usó en neto (consumo, correcciones y devoluciones), por materia prima. Una
     * consulta sobre el libro por lote de las operaciones de la orden.
     *
     * @return array<int, list<array{id: int, numero: string|null, vencimiento: string|null, cantidad: string}>>
     */
    private function lotesUsados(OrdenProduccion $orden): array
    {
        $operaciones = array_values(array_filter([$orden->operacion_consumo_id, $orden->operacion_cierre_id, $orden->operacion_anulacion_id]));

        if ($operaciones === []) {
            return [];
        }

        $filas = DB::table('movimientos_stock_lotes as m')
            ->join('lotes as l', 'l.id', '=', 'm.lote_id')
            ->where('m.empresa_id', $orden->empresa_id)
            ->where('l.empresa_id', $orden->empresa_id)
            ->whereIn('m.operacion_id', $operaciones)
            ->where('m.producto_id', '<>', $orden->producto_id)
            ->groupBy('m.producto_id', 'l.id', 'l.numero', 'l.vencimiento', 'l.es_regularizacion')
            ->havingRaw('SUM(m.cantidad) < 0')
            ->orderBy('l.vencimiento')
            ->selectRaw('m.producto_id, l.id, l.numero, l.vencimiento, l.es_regularizacion, -SUM(m.cantidad) AS cantidad')
            ->get();
        $lotes = [];

        foreach ($filas as $fila) {
            /** @var stdClass $fila */
            $lotes[(int) $fila->producto_id][] = [
                'id' => (int) $fila->id,
                'numero' => (bool) $fila->es_regularizacion ? null : (string) $fila->numero,
                'vencimiento' => $fila->vencimiento === null ? null : substr((string) $fila->vencimiento, 0, 10),
                'cantidad' => (string) $fila->cantidad,
            ];
        }

        return $lotes;
    }

    /**
     * Código PRD-00012 de cada operación de inventario de la orden.
     *
     * @return array<string, string> consumo|cierre|anulacion => código
     */
    private function operaciones(OrdenProduccion $orden): array
    {
        $ids = array_filter(['consumo' => $orden->operacion_consumo_id, 'cierre' => $orden->operacion_cierre_id, 'anulacion' => $orden->operacion_anulacion_id]);

        if ($ids === []) {
            return [];
        }

        $codigos = DB::table('operaciones_inventario')->where('empresa_id', $orden->empresa_id)->whereIn('id', array_values($ids))
            ->get(['id', 'serie', 'numero'])
            ->mapWithKeys(fn (stdClass $o): array => [(int) $o->id => $o->serie.'-'.str_pad((string) $o->numero, 5, '0', STR_PAD_LEFT)]);

        return array_map(fn (int $id): string => (string) ($codigos[$id] ?? ''), $ids);
    }

    private function orden(): OrdenProduccion
    {
        return OrdenProduccion::query()->findOrFail($this->ordenId);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
