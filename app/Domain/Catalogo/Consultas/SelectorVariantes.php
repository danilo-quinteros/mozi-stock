<?php

namespace App\Domain\Catalogo\Consultas;

use App\Domain\Inventario\Consultas\StockLotes;
use App\Support\Decimal;

/**
 * Datos del selector de talla y color (ESPECIFICACION_TALLAS_Y_COLORES.md § 5, etapa V2): las combinaciones ACTIVAS
 * de un modelo con su stock en una sucursal. Usa la misma consulta que la cuadrícula del detalle (CuadriculaStock) y,
 * solo si alguna combinación controla lote, una más para descontar lo vencido (stock vendible, lotes § 4).
 *
 * Es solo para mostrar y elegir: ventas, compras, ajustes y traspasos vuelven a validar stock y precios en el servidor.
 */
final class SelectorVariantes
{
    public function __construct(private readonly StockLotes $lotes) {}

    /**
     * @return array{
     *     tallas: list<array{id: int|null, nombre: string}>,
     *     colores: list<array{id: int|null, nombre: string, muestra: string|null}>,
     *     celdas: array<string, array{id: int, nombre: string, sku: string|null, stock: string, controla_stock: bool, disponible: bool, precio_consumidor: string}>
     * }
     */
    public function delModelo(int $empresaId, int $modeloId, ?int $sucursalId, bool $vendible): array
    {
        $cuadricula = CuadriculaStock::delModelo($empresaId, $modeloId, $sucursalId);
        $activas = array_filter($cuadricula['celdas'], fn (array $celda): bool => $celda['activo']);
        $conLote = array_values(array_map(fn (array $celda): int => $celda['id'],
            array_filter($activas, fn (array $celda): bool => $celda['controla_vencimiento'])));
        $vencidas = $vendible && $sucursalId !== null && $conLote !== [] ? $this->lotes->vencidas($empresaId, $sucursalId, $conLote) : [];
        $celdas = [];
        $tallasUsadas = [];
        $coloresUsados = [];

        foreach ($activas as $clave => $celda) {
            $stock = Decimal::restar($celda['stock'], $vencidas[$celda['id']] ?? '0');
            $celdas[$clave] = [
                'id' => $celda['id'], 'nombre' => $celda['nombre'], 'sku' => $celda['sku'], 'stock' => $stock,
                'controla_stock' => $celda['controla_stock'],
                // Sin stock (o sin sucursal) no se vende; un producto sin control de stock se vende siempre.
                'disponible' => ! $celda['controla_stock'] || ($sucursalId !== null && Decimal::comparar($stock, '0') > 0),
                'precio_consumidor' => $celda['precio_consumidor'],
            ];
            [$talla, $color] = explode('-', $clave);
            $tallasUsadas[(int) $talla] = true;
            $coloresUsados[(int) $color] = true;
        }

        return [
            'tallas' => array_values(array_filter($cuadricula['tallas'], fn (array $t): bool => isset($tallasUsadas[$t['id'] ?? 0]))),
            'colores' => array_values(array_filter($cuadricula['colores'], fn (array $c): bool => isset($coloresUsados[$c['id'] ?? 0]))),
            'celdas' => $celdas,
        ];
    }
}
