<?php

namespace App\Domain\Catalogo\Consultas;

use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Listado de productos con el stock y la alerta de una sucursal. La pantalla y la
 * exportación CSV usan exactamente esta consulta y los mismos filtros.
 */
final class ListadoProductos
{
    /**
     * @return Builder<Producto>
     */
    public static function consulta(int $empresaId, ?int $sucursalId, string $buscar = '', ?int $categoriaId = null, ?string $filtroStock = null): Builder
    {
        $termino = trim($buscar);
        $patron = '%'.addcslashes($termino, '\\%_').'%';

        return Producto::query()
            ->where('productos.empresa_id', $empresaId)
            ->leftJoin('stock_niveles as sn', function (JoinClause $join) use ($sucursalId): void {
                $join->on('sn.producto_id', '=', 'productos.id')
                    ->on('sn.empresa_id', '=', 'productos.empresa_id')
                    ->where('sn.sucursal_id', '=', $sucursalId ?? 0);
            })
            ->leftJoin('unidades_medida as um', 'um.id', '=', 'productos.unidad_medida_id')
            ->select('productos.*')
            ->selectRaw('COALESCE(sn.cantidad, 0) as stock')
            ->selectRaw('COALESCE(sn.stock_minimo, productos.stock_minimo) as minimo')
            ->with(['categoria:id,nombre', 'unidad:id,nombre,abreviatura', 'vinculoExterno:id,empresa_id,producto_id,proveedor_externo_id,externo_id'])
            ->when($termino !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('productos.nombre', 'like', $patron)
                ->orWhere('productos.sku', 'like', $patron)
                ->orWhere('productos.codigo_barras', $termino)
                ->orWhere('productos.contenido', 'like', $patron)
                ->orWhereRaw("CONCAT_WS(' ', productos.nombre, productos.contenido, um.abreviatura) LIKE ?", [$patron])))
            ->when($categoriaId !== null, fn (Builder $q) => $q->where('productos.categoria_id', $categoriaId))
            ->when($filtroStock === 'con_stock', fn (Builder $q) => $q->whereRaw('COALESCE(sn.cantidad, 0) > 0'))
            ->when($filtroStock === 'sin_stock', fn (Builder $q) => $q->whereRaw('COALESCE(sn.cantidad, 0) <= 0'))
            ->orderByDesc('productos.activo')
            ->orderBy('productos.nombre');
    }

    /**
     * Sucursal cuyo stock se muestra: el vendedor siempre ve la suya (se ignora la
     * solicitada); el administrador, la elegida si está activa o la primera activa.
     */
    public static function sucursalParaVista(User $usuario, ?string $solicitada): ?Sucursal
    {
        if ($usuario->esVendedor()) {
            return Sucursal::query()->find($usuario->sucursal_id);
        }

        $activas = Sucursal::query()->where('activa', true)->orderBy('nombre');
        $elegida = $solicitada !== null && ctype_digit($solicitada) ? (clone $activas)->find((int) $solicitada) : null;

        return $elegida ?? $activas->first();
    }

    /**
     * Alerta de una fila del listado: sin stock, stock bajo (en o bajo el mínimo) o normal.
     */
    public static function alerta(string $stock, string $minimo): string
    {
        if (Decimal::comparar($stock, '0') <= 0) {
            return 'sin_stock';
        }

        return Decimal::comparar($minimo, '0') > 0 && Decimal::comparar($stock, $minimo) <= 0 ? 'bajo' : 'normal';
    }

    /**
     * Pares producto-sucursal activos en o bajo su mínimo (solo productos con mínimo > 0).
     */
    public static function contarAlertas(int $empresaId, ?int $sucursalId = null): int
    {
        return (int) DB::table('productos as p')
            ->join('sucursales as s', fn (JoinClause $j) => $j->on('s.empresa_id', '=', 'p.empresa_id')->where('s.activa', true))
            // Con empresa_id el join usa el índice único (empresa, sucursal, producto); sin él, recorre
            // stock_niveles completo por cada producto y sucursal (minutos con miles de productos).
            ->leftJoin('stock_niveles as sn', fn (JoinClause $j) => $j->on('sn.empresa_id', '=', 'p.empresa_id')
                ->on('sn.sucursal_id', '=', 's.id')->on('sn.producto_id', '=', 'p.id'))
            ->where('p.empresa_id', $empresaId)
            ->where('p.activo', true)
            ->when($sucursalId !== null, fn ($q) => $q->where('s.id', $sucursalId))
            ->whereRaw('COALESCE(sn.stock_minimo, p.stock_minimo) > 0')
            ->whereRaw('COALESCE(sn.cantidad, 0) <= COALESCE(sn.stock_minimo, p.stock_minimo)')
            ->count();
    }
}
