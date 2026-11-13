<?php

namespace App\Domain\Catalogo\Consultas;

use App\Support\Decimal;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Cuadrícula de stock tallas × colores de un modelo en una sucursal (ESPECIFICACION_TALLAS_Y_COLORES.md § 5), con UNA
 * consulta: variantes del modelo + su saldo en stock_niveles + nombre y orden de talla y color. Cada celda lleva el
 * mismo semáforo del catálogo (ListadoProductos::alerta) con el mínimo propio de la sucursal o el general.
 * Columnas: tallas en su orden (una sola «—» si el modelo no tiene talla). Filas: colores por nombre (una sola si no
 * tiene color).
 */
final class CuadriculaStock
{
    /**
     * @return array{
     *     tallas: array<int, array{id: int|null, nombre: string}>,
     *     colores: array<int, array{id: int|null, nombre: string, muestra: string|null}>,
     *     celdas: array<string, array{id: int, nombre: string, sku: string|null, codigo_barras: string|null, activo: bool, stock: string, minimo: string, alerta: string, precio_consumidor: string, precio_propio: bool, controla_stock: bool, controla_vencimiento: bool, con_foto: bool}>,
     *     totales_talla: array<int|string, string>, totales_color: array<int|string, string>, total: string
     * }
     */
    public static function delModelo(int $empresaId, int $modeloId, ?int $sucursalId): array
    {
        $filas = DB::table('productos as p')
            ->leftJoin('stock_niveles as sn', fn (JoinClause $j) => $j->on('sn.producto_id', '=', 'p.id')->on('sn.empresa_id', '=', 'p.empresa_id')
                ->where('sn.sucursal_id', '=', $sucursalId ?? 0))
            ->leftJoin('tallas as t', fn (JoinClause $j) => $j->on('t.id', '=', 'p.talla_id')->on('t.empresa_id', '=', 'p.empresa_id'))
            ->leftJoin('colores as c', fn (JoinClause $j) => $j->on('c.id', '=', 'p.color_id')->on('c.empresa_id', '=', 'p.empresa_id'))
            ->where('p.empresa_id', $empresaId)
            ->where('p.modelo_id', $modeloId)
            ->orderByRaw('COALESCE(t.orden, 0)')->orderBy('t.id')->orderBy('c.nombre')
            ->get(['p.id', 'p.nombre', 'p.sku', 'p.codigo_barras', 'p.activo', 'p.talla_id', 'p.color_id', 'p.precio_consumidor', 'p.precio_propio',
                'p.controla_stock', 'p.controla_vencimiento', 'p.imagen_path',
                't.nombre as talla', 'c.nombre as color', 'c.muestra_hex',
                DB::raw('COALESCE(sn.cantidad, 0) as stock'), DB::raw('COALESCE(sn.stock_minimo, p.stock_minimo) as minimo')]);

        $tallas = [];
        $colores = [];
        $celdas = [];
        $porTalla = [];
        $porColor = [];
        $total = '0';

        foreach ($filas as $fila) {
            $tallaId = $fila->talla_id === null ? null : (int) $fila->talla_id;
            $colorId = $fila->color_id === null ? null : (int) $fila->color_id;
            $tallas[$tallaId ?? 0] ??= ['id' => $tallaId, 'nombre' => (string) ($fila->talla ?? '—')];
            $colores[$colorId ?? 0] ??= ['id' => $colorId, 'nombre' => (string) ($fila->color ?? '—'), 'muestra' => $fila->muestra_hex];
            $stock = (string) $fila->stock;
            $activo = (bool) $fila->activo;
            $controla = (bool) $fila->controla_stock;

            $celdas[($tallaId ?? 0).'-'.($colorId ?? 0)] = [
                'id' => (int) $fila->id, 'nombre' => (string) $fila->nombre, 'sku' => $fila->sku, 'codigo_barras' => $fila->codigo_barras,
                'activo' => $activo, 'stock' => $stock, 'minimo' => (string) $fila->minimo,
                'alerta' => ListadoProductos::alerta($stock, (string) $fila->minimo, $controla),
                'precio_consumidor' => (string) $fila->precio_consumidor, 'precio_propio' => (bool) $fila->precio_propio,
                'controla_stock' => $controla, 'controla_vencimiento' => (bool) $fila->controla_vencimiento,
                'con_foto' => $fila->imagen_path !== null,
            ];

            $porTalla[$tallaId ?? 0] = Decimal::sumar($porTalla[$tallaId ?? 0] ?? '0', $stock);
            $porColor[$colorId ?? 0] = Decimal::sumar($porColor[$colorId ?? 0] ?? '0', $stock);
            $total = Decimal::sumar($total, $stock);
        }

        uasort($colores, fn (array $a, array $b): int => strcmp(mb_strtolower($a['nombre']), mb_strtolower($b['nombre'])));

        return [
            'tallas' => array_values($tallas),
            'colores' => array_values($colores),
            'celdas' => $celdas,
            'totales_talla' => $porTalla,
            'totales_color' => $porColor,
            'total' => $total,
        ];
    }
}
