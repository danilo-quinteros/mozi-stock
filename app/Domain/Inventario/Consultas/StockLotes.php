<?php

namespace App\Domain\Inventario\Consultas;

use App\Domain\Inventario\DatosLote;
use App\Domain\Inventario\LibroLotes;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Lecturas del stock por lote para las pantallas (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 4). Solo informan: la
 * venta, el traspaso y la salida se validan otra vez en LibroLotes con los saldos bloqueados. Sin la capacidad o sin
 * productos marcados no consultan tablas de lotes.
 */
final class StockLotes
{
    public function __construct(private readonly LibroLotes $lotes) {}

    /**
     * Unidades vencidas por producto en una sucursal (el stock vendible es el total menos esto, § 6.4).
     *
     * @param  list<int>  $productoIds
     * @return array<int, string> producto_id => cantidad vencida (solo los que tienen)
     */
    public function vencidas(int $empresaId, int $sucursalId, array $productoIds): array
    {
        $conLote = $this->lotes->productosConLote($empresaId, $productoIds);

        if ($conLote === []) {
            return [];
        }

        return DB::table('stock_lotes as sl')
            ->join('lotes as l', 'l.id', '=', 'sl.lote_id')
            ->where('sl.empresa_id', $empresaId)
            ->where('l.empresa_id', $empresaId)
            ->where('sl.sucursal_id', $sucursalId)
            ->whereIn('sl.producto_id', array_keys($conLote))
            ->where('sl.cantidad', '>', 0)
            ->whereNotNull('l.vencimiento')
            ->where('l.vencimiento', '<', DatosLote::hoy())
            ->groupBy('sl.producto_id')
            ->selectRaw('sl.producto_id, SUM(sl.cantidad) AS cantidad')
            ->pluck('cantidad', 'producto_id')
            ->mapWithKeys(fn ($cantidad, $productoId): array => [(int) $productoId => (string) $cantidad])
            ->all();
    }

    /**
     * Lotes con stock de esos productos en una sucursal, en el orden en que los toma una salida automática: vencidos,
     * regularización y luego por fecha. Para el selector de lote de Ajustes (§ 4) y la ficha del producto.
     *
     * @param  list<int>  $productoIds
     * @return array<int, list<array{id: int, numero: string|null, vencimiento: string|null, regularizacion: bool, cantidad: string, vencido: bool}>>
     */
    public function conStock(int $empresaId, int $sucursalId, array $productoIds): array
    {
        $conLote = $this->lotes->productosConLote($empresaId, $productoIds);

        if ($conLote === []) {
            return [];
        }

        $hoy = DatosLote::hoy();
        $resultado = [];

        $filas = DB::table('stock_lotes as sl')
            ->join('lotes as l', 'l.id', '=', 'sl.lote_id')
            ->where('sl.empresa_id', $empresaId)
            ->where('l.empresa_id', $empresaId)
            ->where('sl.sucursal_id', $sucursalId)
            ->whereIn('sl.producto_id', array_keys($conLote))
            ->where('sl.cantidad', '>', 0)
            ->get(['sl.producto_id', 'l.id', 'l.numero', 'l.vencimiento', 'l.es_regularizacion', 'sl.cantidad']);

        foreach ($filas as $fila) {
            $resultado[(int) $fila->producto_id][] = self::fila($fila, $hoy);
        }

        foreach ($resultado as &$lista) {
            usort($lista, fn (array $a, array $b): int => self::orden($a) <=> self::orden($b));
        }

        return $resultado;
    }

    /**
     * Lotes con stock de un producto en todas las sucursales activas, para la ficha del producto (§ 4): por sucursal y
     * en el orden de una salida automática.
     *
     * @return list<array{id: int, numero: string|null, vencimiento: string|null, regularizacion: bool, cantidad: string, vencido: bool, sucursal: string}>
     */
    public function delProducto(int $empresaId, int $productoId): array
    {
        if ($this->lotes->productosConLote($empresaId, [$productoId]) === []) {
            return [];
        }

        $hoy = DatosLote::hoy();
        $lista = DB::table('stock_lotes as sl')
            ->join('lotes as l', 'l.id', '=', 'sl.lote_id')
            ->join('sucursales as s', 's.id', '=', 'sl.sucursal_id')
            ->where('sl.empresa_id', $empresaId)
            ->where('l.empresa_id', $empresaId)
            ->where('s.empresa_id', $empresaId)
            ->where('s.activa', true)
            ->where('sl.producto_id', $productoId)
            ->where('sl.cantidad', '>', 0)
            ->get(['l.id', 'l.numero', 'l.vencimiento', 'l.es_regularizacion', 'sl.cantidad', 's.nombre as sucursal'])
            ->map(fn (stdClass $fila): array => self::fila($fila, $hoy) + ['sucursal' => (string) $fila->sucursal])
            ->all();

        usort($lista, fn (array $a, array $b): int => [$a['sucursal'], ...self::orden($a)] <=> [$b['sucursal'], ...self::orden($b)]);

        return $lista;
    }

    /**
     * @return array{id: int, numero: string|null, vencimiento: string|null, regularizacion: bool, cantidad: string, vencido: bool}
     */
    private static function fila(stdClass $fila, string $hoy): array
    {
        $vencimiento = $fila->vencimiento === null ? null : substr((string) $fila->vencimiento, 0, 10);

        return [
            'id' => (int) $fila->id,
            'numero' => $fila->numero === null ? null : (string) $fila->numero,
            'vencimiento' => $vencimiento,
            'regularizacion' => (bool) $fila->es_regularizacion,
            'cantidad' => (string) $fila->cantidad,
            'vencido' => DatosLote::vencido($vencimiento, $hoy),
        ];
    }

    /**
     * @param  array{id: int, vencimiento: string|null, regularizacion: bool, vencido: bool}  $lote
     * @return array{int, string, int}
     */
    private static function orden(array $lote): array
    {
        $rango = match (true) {
            $lote['vencido'] => 0,
            $lote['regularizacion'] => 1,
            default => 2,
        };

        return [$rango, (string) $lote['vencimiento'], $lote['id']];
    }

    /**
     * Texto de un lote para listas y selectores: «Lote A-12 · vence 10/03/2027» o «Sin lote (regularización)».
     *
     * @param  array{numero: string|null, vencimiento: string|null, regularizacion: bool}  $lote
     */
    public static function etiqueta(array $lote): string
    {
        return $lote['regularizacion']
            ? __('Sin lote (regularización)')
            : __('Lote :numero · vence :fecha', ['numero' => $lote['numero'], 'fecha' => DatosLote::formatear($lote['vencimiento'])]);
    }
}
