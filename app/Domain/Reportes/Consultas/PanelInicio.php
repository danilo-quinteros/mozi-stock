<?php

namespace App\Domain\Reportes\Consultas;

use App\Domain\Reportes\Periodo;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bloques del Inicio (index.html) una vez terminada la guía de configuración. Cada bloque es una
 * consulta agrupada y filtrada por empresa, nunca una por fila. Dinero cobrado y valor vendido
 * siguen separados (§ 3.11). El vendedor ve solo su sucursal y, en ventas y cobros, lo suyo.
 */
final class PanelInicio
{
    public const DIAS_SERIE = 7;

    public const DIAS_RANKING = 30;

    /**
     * Dinero recibido por día de negocio en los últimos 7 días, con los días sin cobros en 0.
     *
     * @return list<array{fecha: CarbonImmutable, total: string, altura: int}>
     */
    public static function cobrosPorDia(int $empresaId, ?int $sucursalId): array
    {
        $periodo = self::ultimosDias(self::DIAS_SERIE);

        return self::serie($periodo, Cobros::porDia($empresaId, $periodo, $sucursalId));
    }

    /**
     * Valor vendido por un vendedor en su sucursal, por día de negocio de los últimos 7 días.
     *
     * @return list<array{fecha: CarbonImmutable, total: string, altura: int}>
     */
    public static function ventasPorDia(int $empresaId, int $sucursalId, int $vendedorId): array
    {
        $periodo = self::ultimosDias(self::DIAS_SERIE);
        [$inicio, $fin] = $periodo->limitesUtc();
        $totales = DB::table('ventas')
            ->where('empresa_id', $empresaId)
            ->where('vendedor_id', $vendedorId)
            ->where('sucursal_id', $sucursalId)
            ->where('fecha', '>=', $inicio)
            ->where('fecha', '<', $fin)
            ->selectRaw("DATE(CONVERT_TZ(fecha, '+00:00', ?)) AS dia", [$periodo->desfase()])
            ->selectRaw('SUM(total) AS total')
            ->groupBy('dia')
            ->pluck('total', 'dia')
            ->all();

        return self::serie($periodo, $totales);
    }

    /**
     * Lo que un vendedor vendió y cobró hoy. Lo cobrado sale del libro de caja (abonos incluidos).
     *
     * @return array{vendido: string, ventas: int, cobrado: string}
     */
    public static function actividadDeHoy(int $empresaId, int $sucursalId, int $usuarioId): array
    {
        [$inicio, $fin] = Periodo::hoy()->limitesUtc();
        $ventas = DB::table('ventas')
            ->where('empresa_id', $empresaId)
            ->where('vendedor_id', $usuarioId)
            ->where('sucursal_id', $sucursalId)
            ->where('fecha', '>=', $inicio)
            ->where('fecha', '<', $fin)
            ->selectRaw('COALESCE(SUM(total), 0) AS total, COUNT(*) AS cantidad')
            ->first();
        $cobrado = DB::table('movimientos_caja')
            ->where('empresa_id', $empresaId)
            ->where('usuario_id', $usuarioId)
            ->where('sentido', 'ingreso')
            ->where('fecha_operacion', '>=', $inicio)
            ->where('fecha_operacion', '<', $fin)
            ->selectRaw('COALESCE(SUM(monto), 0) AS total')
            ->value('total');

        return [
            'vendido' => Decimal::redondear((string) ($ventas->total ?? '0')),
            'ventas' => (int) ($ventas->cantidad ?? 0),
            'cobrado' => Decimal::redondear((string) $cobrado),
        ];
    }

    public static function pedidosPendientes(int $empresaId, ?int $sucursalId): int
    {
        return DB::table('pedidos')
            ->where('empresa_id', $empresaId)
            ->where('estado', 'pendiente')
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('sucursal_id', $sucursalId))
            ->count();
    }

    /**
     * Últimas ventas confirmadas, con su saldo y lo devuelto hasta hoy.
     *
     * @return Collection<int, \stdClass>
     */
    public static function ultimasVentas(int $empresaId, ?int $sucursalId, ?int $vendedorId = null, int $limite = 6): Collection
    {
        return DB::table('ventas as v')
            ->join('users as vend', 'vend.id', '=', 'v.vendedor_id')
            ->join('sucursales as s', 's.id', '=', 'v.sucursal_id')
            ->leftJoin('clientes as c', 'c.id', '=', 'v.cliente_id')
            ->where('v.empresa_id', $empresaId)
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('v.sucursal_id', $sucursalId))
            ->when($vendedorId !== null, fn (Builder $q) => $q->where('v.vendedor_id', $vendedorId))
            ->orderByDesc('v.fecha')
            ->orderByDesc('v.id')
            ->limit($limite)
            ->get(['v.id', 'v.numero', 'v.fecha', 'v.total', 'v.devuelto', 'v.saldo_pendiente',
                'c.nombre as cliente', 'vend.name as vendedor', 's.nombre as sucursal']);
    }

    /**
     * Productos con más valor vendido en los últimos 30 días, descontando lo devuelto de esas
     * mismas ventas. Una devolución siempre es posterior a su venta, así que solo se leen las
     * devoluciones del mismo rango.
     *
     * @return list<array{nombre: string, unidad: string, cantidad: string, importe: string, ancho: int}>
     */
    public static function masVendidos(int $empresaId, ?int $sucursalId, int $limite = 5): array
    {
        [$inicio, $fin] = self::ultimosDias(self::DIAS_RANKING)->limitesUtc();
        $devuelto = DB::table('devolucion_items')
            ->where('empresa_id', $empresaId)
            ->where('created_at', '>=', $inicio)
            ->groupBy('venta_item_id')
            ->select(['venta_item_id', DB::raw('SUM(cantidad) AS cantidad')]);
        $neta = 'vi.cantidad - COALESCE(dv.cantidad, 0)';

        $filas = DB::table('venta_items as vi')
            ->join('ventas as v', 'v.id', '=', 'vi.venta_id')
            ->leftJoinSub($devuelto, 'dv', 'dv.venta_item_id', '=', 'vi.id')
            ->where('v.empresa_id', $empresaId)
            ->where('v.fecha', '>=', $inicio)
            ->where('v.fecha', '<', $fin)
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('v.sucursal_id', $sucursalId))
            ->groupBy('vi.producto_id')
            ->havingRaw("SUM({$neta}) > 0")
            ->orderByDesc('importe')
            ->orderBy('nombre')
            ->limit($limite)
            ->get([DB::raw('MAX(vi.descripcion) AS nombre'), DB::raw('MAX(vi.unidad) AS unidad'),
                DB::raw("SUM({$neta}) AS cantidad"), DB::raw("SUM(vi.total * ({$neta}) / vi.cantidad) AS importe")]);

        $maximo = Decimal::redondear((string) ($filas->first()->importe ?? '0'));

        return array_values($filas->map(fn (\stdClass $fila): array => [
            'nombre' => (string) $fila->nombre,
            'unidad' => (string) $fila->unidad,
            'cantidad' => (string) $fila->cantidad,
            'importe' => Decimal::redondear((string) $fila->importe),
            'ancho' => self::proporcion(Decimal::redondear((string) $fila->importe), $maximo),
        ])->all());
    }

    /**
     * Productos en o bajo su mínimo, primero los que tienen menos existencias frente a su mínimo.
     *
     * @return Collection<int, \stdClass>
     */
    public static function stockBajo(int $empresaId, ?int $sucursalId, int $limite = 5): Collection
    {
        return InventarioSucursales::consulta($empresaId, $sucursalId, '', true)
            ->reorder()
            ->orderByRaw('COALESCE(sn.cantidad, 0) / COALESCE(sn.stock_minimo, p.stock_minimo)')
            ->orderBy('p.nombre')
            ->limit($limite)
            ->get();
    }

    /**
     * Del día de hoy hacia atrás, en días de negocio.
     */
    private static function ultimosDias(int $dias): Periodo
    {
        $hoy = Periodo::hoy()->desde;

        return Periodo::rango($hoy->subDays($dias - 1)->format('Y-m-d'), $hoy->format('Y-m-d'));
    }

    /**
     * @param  array<string, string|int|float>  $totales  por fecha Y-m-d
     * @return list<array{fecha: CarbonImmutable, total: string, altura: int}>
     */
    private static function serie(Periodo $periodo, array $totales): array
    {
        $dias = [];
        for ($d = $periodo->desde; $d->lessThanOrEqualTo($periodo->hasta); $d = $d->addDay()) {
            $dias[] = ['fecha' => $d, 'total' => Decimal::redondear((string) ($totales[$d->format('Y-m-d')] ?? '0'))];
        }

        $maximo = array_reduce($dias, fn (string $max, array $dia) => Decimal::comparar($dia['total'], $max, 2) > 0 ? $dia['total'] : $max, '0.00');

        return array_map(fn (array $dia): array => $dia + ['altura' => self::proporcion($dia['total'], $maximo)], $dias);
    }

    /**
     * Porcentaje entero de un importe frente al mayor, solo para dibujar barras.
     */
    private static function proporcion(string $valor, string $maximo): int
    {
        return Decimal::comparar($maximo, '0', 2) > 0
            ? (int) Decimal::dividir(Decimal::multiplicar($valor, '100', 2), $maximo, 0)
            : 0;
    }
}
