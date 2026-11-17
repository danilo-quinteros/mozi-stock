<?php

namespace App\Domain\Produccion\Consultas;

use App\Domain\Catalogo\Consultas\ListadoProductos;
use App\Models\Producto;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Listado de materias primas con el stock de una sucursal (ESPECIFICACION_PRODUCCION.md § 3 y § 4.3). Reutiliza la
 * consulta del catálogo (ListadoProductos) con la clase «materia_prima», así que el filtro por empresa, el saldo y el
 * mínimo efectivo de la sucursal son los mismos. La pantalla y el CSV de la sugerencia de compra usan esta consulta.
 *
 * Filtros: todas, bajo mínimo, sobre el máximo y sugerencia de compra (las que están bajo el mínimo, con la cantidad
 * que conviene comprar).
 */
final class ListadoMateriasPrimas
{
    public const FILTROS = ['todas', 'bajo_minimo', 'sobre_maximo', 'sugerencia'];

    private const BAJO_MINIMO = 'COALESCE(sn.stock_minimo, productos.stock_minimo) > 0 AND COALESCE(sn.cantidad, 0) <= COALESCE(sn.stock_minimo, productos.stock_minimo)';

    private const SOBRE_MAXIMO = 'productos.stock_maximo IS NOT NULL AND COALESCE(sn.cantidad, 0) > productos.stock_maximo';

    /**
     * @return Builder<Producto>
     */
    public static function consulta(int $empresaId, ?int $sucursalId, string $buscar = '', string $filtro = 'todas'): Builder
    {
        return ListadoProductos::consulta($empresaId, $sucursalId, $buscar, clase: Producto::CLASE_MATERIA_PRIMA)
            ->when(in_array($filtro, ['bajo_minimo', 'sugerencia'], true), fn (Builder $q) => $q->where('productos.activo', true)->whereRaw(self::BAJO_MINIMO))
            ->when($filtro === 'sobre_maximo', fn (Builder $q) => $q->where('productos.activo', true)->whereRaw(self::SOBRE_MAXIMO));
    }

    /**
     * Cuántas materias primas activas están bajo el mínimo y cuántas sobre el máximo en la sucursal (una consulta).
     *
     * @return array{total: int, bajo_minimo: int, sobre_maximo: int}
     */
    public static function resumen(int $empresaId, ?int $sucursalId): array
    {
        $fila = DB::table('productos')
            ->leftJoin('stock_niveles as sn', fn ($j) => $j->on('sn.producto_id', '=', 'productos.id')
                ->on('sn.empresa_id', '=', 'productos.empresa_id')->where('sn.sucursal_id', '=', $sucursalId ?? 0))
            ->where('productos.empresa_id', $empresaId)
            ->where('productos.clase', Producto::CLASE_MATERIA_PRIMA)
            ->where('productos.activo', true)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN '.self::BAJO_MINIMO.' THEN 1 ELSE 0 END) as bajo_minimo')
            ->selectRaw('SUM(CASE WHEN '.self::SOBRE_MAXIMO.' THEN 1 ELSE 0 END) as sobre_maximo')
            ->first();

        return [
            'total' => (int) ($fila->total ?? 0),
            'bajo_minimo' => (int) ($fila->bajo_minimo ?? 0),
            'sobre_maximo' => (int) ($fila->sobre_maximo ?? 0),
        ];
    }

    /**
     * Aviso de una fila: sin stock, bajo el mínimo, sobre el máximo o normal.
     */
    public static function aviso(string $stock, string $minimo, ?string $maximo): string
    {
        if (Decimal::comparar($stock, '0') <= 0) {
            return 'sin_stock';
        }

        if (Decimal::comparar($minimo, '0') > 0 && Decimal::comparar($stock, $minimo) <= 0) {
            return 'bajo';
        }

        return $maximo !== null && Decimal::comparar($stock, $maximo) > 0 ? 'sobre' : 'normal';
    }

    /**
     * Cantidad sugerida para comprar (§ 4.3): hasta el máximo o, si no tiene, hasta el mínimo. Null si no está bajo el
     * mínimo o no hace falta comprar.
     */
    public static function sugerido(string $stock, string $minimo, ?string $maximo): ?string
    {
        if (Decimal::comparar($minimo, '0') <= 0 || Decimal::comparar($stock, $minimo) > 0) {
            return null;
        }

        $faltan = Decimal::restar($maximo ?? $minimo, $stock);

        return Decimal::comparar($faltan, '0') > 0 ? $faltan : null;
    }
}
