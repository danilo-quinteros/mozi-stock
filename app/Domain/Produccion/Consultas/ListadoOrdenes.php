<?php

namespace App\Domain\Produccion\Consultas;

use App\Enums\EstadoOrdenProduccion;
use App\Models\OrdenProduccion;
use App\Models\Producto;
use Illuminate\Database\Eloquent\Builder;

/**
 * Listado de órdenes de producción (ESPECIFICACION_PRODUCCION.md § 3 y § 6). El scope de empresa filtra las órdenes y
 * el join exige el mismo empresa_id en productos. Las abiertas se ordenan por fecha programada (lo próximo a producir
 * primero); el resto, de la más nueva a la más antigua. Usa el índice (empresa_id, estado, fecha_programada).
 */
final class ListadoOrdenes
{
    public const ESTADOS = ['abiertas', 'finalizadas', 'anuladas', 'todas'];

    /**
     * @return Builder<OrdenProduccion>
     */
    public static function consulta(string $buscar = '', string $estado = 'abiertas'): Builder
    {
        $termino = trim($buscar);
        $numero = preg_match('/^(?:OP-?)?0*(\d{1,9})$/i', $termino, $m) === 1 ? (int) $m[1] : null;

        $consulta = OrdenProduccion::query()
            ->select('ordenes_produccion.*')
            ->join('productos', fn ($j) => $j->on('productos.id', '=', 'ordenes_produccion.producto_id')->whereColumn('productos.empresa_id', 'ordenes_produccion.empresa_id'))
            ->leftJoin('unidades_medida as um', 'um.id', '=', 'productos.unidad_medida_id')
            ->when($termino !== '', fn (Builder $q) => $q->where(function (Builder $q) use ($termino, $numero): void {
                Producto::filtrarPorTexto($q, $termino);

                if ($numero !== null) {
                    $q->orWhere('ordenes_produccion.numero', $numero);
                }
            }))
            ->with([
                'producto' => fn ($q) => $q->select(['id', 'empresa_id', 'nombre', 'contenido', 'sku', 'unidad_medida_id'])->with('unidad:id,abreviatura'),
                'sucursal:id,nombre',
            ]);

        $estados = match ($estado) {
            'finalizadas' => [EstadoOrdenProduccion::Finalizada],
            'anuladas' => [EstadoOrdenProduccion::Anulada],
            'todas' => null,
            default => EstadoOrdenProduccion::abiertas(),
        };

        if ($estados !== null) {
            $consulta->whereIn('ordenes_produccion.estado', array_map(fn (EstadoOrdenProduccion $e): string => $e->value, $estados));
        }

        return $estado === 'abiertas'
            ? $consulta->orderBy('ordenes_produccion.fecha_programada')->orderBy('ordenes_produccion.numero')
            : $consulta->orderByDesc('ordenes_produccion.numero');
    }
}
