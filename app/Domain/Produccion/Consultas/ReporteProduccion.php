<?php

namespace App\Domain\Produccion\Consultas;

use App\Domain\Reportes\Periodo;
use App\Enums\EstadoOrdenProduccion;
use App\Models\Producto;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reportes de producción (ESPECIFICACION_PRODUCCION.md § 12, etapa P4). Todos leen las órdenes **finalizadas** cuya
 * fecha de finalización cae en el período (días de La Paz, como el resto de reportes): lo producido, su rendimiento
 * (planificado contra obtenido), su costo y el consumo real de materias primas. Las órdenes aprobadas sin finalizar ya
 * descontaron materia prima, pero eso se ve en el historial de inventario (tipo Producción), no aquí.
 *
 * Cada consulta filtra por empresa_id explícito (son consultas de tabla, sin el scope de Eloquent) y los joins exigen
 * el mismo empresa_id. Las sumas son DECIMAL en MySQL y los cocientes se calculan con bcmath, nunca float. Usa el índice
 * (empresa_id, estado, finalizada_en) y, para las materias primas, el único (empresa_id, orden_id, producto_id).
 */
final class ReporteProduccion
{
    public const PESTANAS = ['productos', 'ordenes', 'materias'];

    /**
     * Totales del período (sin la búsqueda): órdenes finalizadas, su costo y cuántas tuvieron merma.
     *
     * @return array{ordenes: int, costo: string, con_merma: int}
     */
    public static function resumen(int $empresaId, Periodo $periodo, ?int $sucursalId = null): array
    {
        $fila = self::finalizadas($empresaId, $periodo, $sucursalId)
            ->selectRaw('COUNT(*) AS ordenes, COALESCE(SUM(o.costo_total), 0) AS costo')
            ->selectRaw('COALESCE(SUM(CASE WHEN o.cantidad_obtenida < o.cantidad_planificada THEN 1 ELSE 0 END), 0) AS con_merma')
            ->first();

        return [
            'ordenes' => (int) ($fila->ordenes ?? 0),
            'costo' => Decimal::redondear((string) ($fila->costo ?? '0')),
            'con_merma' => (int) ($fila->con_merma ?? 0),
        ];
    }

    /**
     * Producción del período por producto: órdenes, planificado, obtenido y costo, sumados.
     */
    public static function porProducto(int $empresaId, Periodo $periodo, ?int $sucursalId = null, string $buscar = ''): Builder
    {
        return self::conProducto($empresaId, $periodo, $sucursalId, $buscar)
            ->groupBy('p.id', 'p.nombre', 'p.contenido', 'p.sku', 'um.abreviatura')
            ->orderBy('p.nombre')
            ->orderBy('p.id')
            ->select(['p.id', DB::raw(Producto::sqlNombreCompleto().' as nombre'), 'p.sku', 'um.abreviatura as unidad'])
            ->selectRaw('COUNT(*) AS ordenes, SUM(o.cantidad_planificada) AS planificada, SUM(o.cantidad_obtenida) AS obtenida, SUM(o.costo_total) AS costo_total');
    }

    /**
     * Cada orden finalizada del período, de la más reciente a la más antigua, con su rendimiento y costo.
     */
    public static function porOrden(int $empresaId, Periodo $periodo, ?int $sucursalId = null, string $buscar = ''): Builder
    {
        return self::conProducto($empresaId, $periodo, $sucursalId, $buscar)
            ->join('sucursales as s', fn ($j) => $j->on('s.id', '=', 'o.sucursal_id')->whereColumn('s.empresa_id', 'o.empresa_id'))
            ->orderByDesc('o.finalizada_en')
            ->orderByDesc('o.id')
            ->select(['o.id', 'o.numero', 'o.finalizada_en', 'o.cantidad_planificada as planificada', 'o.cantidad_obtenida as obtenida',
                'o.costo_total', 'o.costo_unitario', DB::raw(Producto::sqlNombreCompleto().' as nombre'), 'p.sku', 'um.abreviatura as unidad', 's.nombre as sucursal']);
    }

    /**
     * Consumo de materias primas de las órdenes finalizadas del período: teórico (lo de la receta escalada), usado
     * (el consumo real registrado al finalizar) y su costo (usado × costo guardado al aprobar).
     */
    public static function materias(int $empresaId, Periodo $periodo, ?int $sucursalId = null, string $buscar = ''): Builder
    {
        $termino = trim($buscar);

        return self::finalizadas($empresaId, $periodo, $sucursalId)
            ->join('orden_produccion_items as i', fn ($j) => $j->on('i.orden_id', '=', 'o.id')->whereColumn('i.empresa_id', 'o.empresa_id'))
            ->join('productos as p', fn ($j) => $j->on('p.id', '=', 'i.producto_id')->whereColumn('p.empresa_id', 'i.empresa_id'))
            ->leftJoin('unidades_medida as um', 'um.id', '=', 'p.unidad_medida_id')
            ->when($termino !== '', fn (Builder $q) => Producto::filtrarPorTexto($q, $termino, 'p', 'um'))
            ->groupBy('p.id', 'p.nombre', 'p.contenido', 'p.sku', 'um.abreviatura')
            ->orderBy('p.nombre')
            ->orderBy('p.id')
            ->select(['p.id', DB::raw(Producto::sqlNombreCompleto().' as nombre'), 'p.sku', 'um.abreviatura as unidad'])
            ->selectRaw('COUNT(DISTINCT o.id) AS ordenes, SUM(i.cantidad_teorica) AS teorica, SUM(i.cantidad_real) AS usada')
            ->selectRaw('SUM(i.cantidad_real * i.costo_unitario) AS costo');
    }

    /**
     * Lo obtenido sobre lo planificado, en porcentaje con 1 decimal (100 = exacto; menos = merma).
     */
    public static function rendimiento(string $planificada, string $obtenida): ?string
    {
        if (Decimal::comparar($planificada, '0') <= 0) {
            return null;
        }

        return Decimal::redondear(Decimal::dividir(Decimal::multiplicar($obtenida, '100', 6), $planificada, 6), 1);
    }

    /**
     * Lo usado respecto de lo teórico, en porcentaje con 1 decimal (positivo = se usó más).
     */
    public static function desvio(string $teorica, string $usada): ?string
    {
        if (Decimal::comparar($teorica, '0') <= 0) {
            return null;
        }

        return Decimal::redondear(Decimal::dividir(Decimal::multiplicar(Decimal::restar($usada, $teorica), '100', 6), $teorica, 6), 1);
    }

    /**
     * Costo total ÷ cantidad, redondeado a 6 decimales igual que el costo unitario de una orden (FinalizarOrden).
     */
    public static function costoUnitario(string $costo, string $cantidad): ?string
    {
        return Decimal::comparar($cantidad, '0') > 0 ? Decimal::redondear(Decimal::dividir($costo, $cantidad, 8), 6) : null;
    }

    private static function finalizadas(int $empresaId, Periodo $periodo, ?int $sucursalId): Builder
    {
        [$inicio, $fin] = $periodo->limitesUtc();

        return DB::table('ordenes_produccion as o')
            ->where('o.empresa_id', $empresaId)
            ->where('o.estado', EstadoOrdenProduccion::Finalizada->value)
            ->where('o.finalizada_en', '>=', $inicio)
            ->where('o.finalizada_en', '<', $fin)
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('o.sucursal_id', $sucursalId));
    }

    /**
     * Órdenes finalizadas con su producto; la búsqueda acepta el producto (nombre, código…) o el número «OP-0015».
     */
    private static function conProducto(int $empresaId, Periodo $periodo, ?int $sucursalId, string $buscar): Builder
    {
        $termino = trim($buscar);
        $numero = preg_match('/^(?:OP-?)?0*(\d{1,9})$/i', $termino, $m) === 1 ? (int) $m[1] : null;

        return self::finalizadas($empresaId, $periodo, $sucursalId)
            ->join('productos as p', fn ($j) => $j->on('p.id', '=', 'o.producto_id')->whereColumn('p.empresa_id', 'o.empresa_id'))
            ->leftJoin('unidades_medida as um', 'um.id', '=', 'p.unidad_medida_id')
            ->when($termino !== '', fn (Builder $q) => $q->where(function (Builder $q) use ($termino, $numero): void {
                Producto::filtrarPorTexto($q, $termino, 'p', 'um');

                if ($numero !== null) {
                    $q->orWhere('o.numero', $numero);
                }
            }));
    }
}
