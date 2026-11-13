<?php

namespace App\Domain\Catalogo\Consultas;

use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Listado de productos con el stock y la alerta de una sucursal. La pantalla y la
 * exportación CSV usan exactamente esta consulta y los mismos filtros. Los productos sin
 * control de stock muestran «Sin control» y no entran en los filtros ni en las alertas de stock.
 *
 * Tallas y colores (ESPECIFICACION_TALLAS_Y_COLORES.md § 5): con la capacidad activa, la pantalla agrupa las
 * variantes de cada modelo en una fila (entradas()) y filtra «Modelos / Sueltos» (tipo). Sin la capacidad, cada
 * variante se lista como un producto suelto, igual que siempre.
 */
final class ListadoProductos
{
    public const TIPOS = ['modelos', 'sueltos'];

    /**
     * @param  string|null  $tipo  'modelos' (solo variantes), 'sueltos' (sin modelo) o null (todos)
     * @param  string|null  $marca  solo los productos de esa marca (null o vacío = todas)
     * @return Builder<Producto>
     */
    public static function consulta(int $empresaId, ?int $sucursalId, string $buscar = '', ?int $categoriaId = null, ?string $filtroStock = null, ?string $tipo = null, ?string $marca = null): Builder
    {
        return self::filtrada($empresaId, $sucursalId, $buscar, $categoriaId, $filtroStock, $tipo, $marca)
            ->select('productos.*')
            ->selectRaw('COALESCE(sn.cantidad, 0) as stock')
            ->selectRaw('COALESCE(sn.stock_minimo, productos.stock_minimo) as minimo')
            ->with(['categoria:id,nombre', 'unidad:id,nombre,abreviatura', 'vinculoExterno:id,empresa_id,producto_id,proveedor_externo_id,externo_id'])
            ->orderByDesc('productos.activo')
            ->orderBy('productos.nombre');
    }

    /**
     * Catálogo agrupado (tallas y colores § 5): una entrada por producto suelto y una por modelo con al menos una
     * variante que cumple los filtros (la búsqueda encuentra el modelo por su nombre, porque está en el nombre de cada
     * variante, y por el SKU o el código de barras de cualquier variante). Paginado sobre las entradas, con los mismos
     * filtros del listado plano. Orden: activos primero y por nombre (del modelo o del producto).
     *
     * $soloActivos deja fuera productos, combinaciones y modelos inactivos (POS). $pagina fija la página cuando la
     * pantalla no usa la paginación de Livewire (si es null se toma de la petición).
     *
     * @return LengthAwarePaginator<int, object{tipo: string, entrada_id: int}>
     */
    public static function entradas(int $empresaId, ?int $sucursalId, string $buscar, ?int $categoriaId, ?string $filtroStock, ?string $tipo, int $porPagina = 25, bool $soloActivos = false, ?int $pagina = null, ?string $marca = null): LengthAwarePaginator
    {
        $esModelo = "CASE WHEN productos.modelo_id IS NULL THEN 'producto' ELSE 'modelo' END";
        $entrada = 'COALESCE(productos.modelo_id, productos.id)';

        /** @var LengthAwarePaginator<int, object{tipo: string, entrada_id: int}> */
        return self::filtrada($empresaId, $sucursalId, $buscar, $categoriaId, $filtroStock, $tipo, $marca)->toBase()
            ->leftJoin('modelos as mo', fn (JoinClause $j) => $j->on('mo.id', '=', 'productos.modelo_id')->on('mo.empresa_id', '=', 'productos.empresa_id'))
            ->when($soloActivos, fn ($q) => $q->where('productos.activo', true)->where(fn ($q) => $q->whereNull('productos.modelo_id')->orWhere('mo.activo', true)))
            ->selectRaw("{$esModelo} as tipo")
            ->selectRaw("{$entrada} as entrada_id")
            ->selectRaw('MAX(COALESCE(mo.activo, productos.activo)) as activo_orden')
            ->selectRaw('MIN(COALESCE(mo.nombre, productos.nombre)) as nombre_orden')
            ->groupByRaw("{$esModelo}, {$entrada}")
            ->orderByDesc('activo_orden')->orderBy('nombre_orden')->orderBy('entrada_id')
            ->paginate($porPagina, ['*'], 'page', $pagina);
    }

    /**
     * Totales de las variantes activas de cada modelo en una sucursal (una consulta): cuántas son, stock total,
     * cuántas están sin stock o con stock bajo, cuántas tienen precio propio y cuántas no controlan stock.
     *
     * @param  list<int>  $modeloIds
     * @return array<int, array{variantes: int, stock: string, alertas: int, precio_propio: int, sin_control: int}>
     */
    public static function resumenModelos(int $empresaId, ?int $sucursalId, array $modeloIds): array
    {
        if ($modeloIds === []) {
            return [];
        }

        return DB::table('productos as p')
            ->leftJoin('stock_niveles as sn', fn (JoinClause $j) => $j->on('sn.producto_id', '=', 'p.id')->on('sn.empresa_id', '=', 'p.empresa_id')
                ->where('sn.sucursal_id', '=', $sucursalId ?? 0))
            ->where('p.empresa_id', $empresaId)
            ->whereIn('p.modelo_id', $modeloIds)
            ->where('p.activo', true)
            ->groupBy('p.modelo_id')
            ->get([
                'p.modelo_id',
                DB::raw('COUNT(*) as variantes'),
                DB::raw('COALESCE(SUM(sn.cantidad), 0) as stock'),
                DB::raw('SUM(CASE WHEN p.controla_stock = 1 AND (COALESCE(sn.cantidad, 0) <= 0 OR (COALESCE(sn.stock_minimo, p.stock_minimo) > 0 AND COALESCE(sn.cantidad, 0) <= COALESCE(sn.stock_minimo, p.stock_minimo))) THEN 1 ELSE 0 END) as alertas'),
                DB::raw('SUM(CASE WHEN p.precio_propio = 1 THEN 1 ELSE 0 END) as precio_propio'),
                DB::raw('SUM(CASE WHEN p.controla_stock = 0 THEN 1 ELSE 0 END) as sin_control'),
            ])
            ->mapWithKeys(fn (object $fila): array => [(int) $fila->modelo_id => [
                'variantes' => (int) $fila->variantes, 'stock' => (string) $fila->stock,
                'alertas' => (int) $fila->alertas, 'precio_propio' => (int) $fila->precio_propio,
                'sin_control' => (int) $fila->sin_control,
            ]])->all();
    }

    /**
     * Productos de la empresa con el saldo de la sucursal unido y los filtros aplicados, sin columnas ni orden.
     *
     * @return Builder<Producto>
     */
    private static function filtrada(int $empresaId, ?int $sucursalId, string $buscar, ?int $categoriaId, ?string $filtroStock, ?string $tipo, ?string $marca = null): Builder
    {
        $termino = trim($buscar);
        $marca = Producto::normalizarMarca($marca);

        return Producto::query()
            ->where('productos.empresa_id', $empresaId)
            ->leftJoin('stock_niveles as sn', function (JoinClause $join) use ($sucursalId): void {
                $join->on('sn.producto_id', '=', 'productos.id')
                    ->on('sn.empresa_id', '=', 'productos.empresa_id')
                    ->where('sn.sucursal_id', '=', $sucursalId ?? 0);
            })
            ->leftJoin('unidades_medida as um', 'um.id', '=', 'productos.unidad_medida_id')
            ->when($termino !== '', fn (Builder $q) => Producto::filtrarPorTexto($q, $termino))
            ->when($categoriaId !== null, fn (Builder $q) => $q->where('productos.categoria_id', $categoriaId))
            // Índice (empresa_id, marca).
            ->when($marca !== null, fn (Builder $q) => $q->where('productos.marca', $marca))
            ->when($filtroStock === 'con_stock', fn (Builder $q) => $q->where('productos.controla_stock', true)->whereRaw('COALESCE(sn.cantidad, 0) > 0'))
            ->when($filtroStock === 'sin_stock', fn (Builder $q) => $q->where('productos.controla_stock', true)->whereRaw('COALESCE(sn.cantidad, 0) <= 0'))
            ->when($tipo === 'modelos', fn (Builder $q) => $q->whereNotNull('productos.modelo_id'))
            ->when($tipo === 'sueltos', fn (Builder $q) => $q->whereNull('productos.modelo_id'));
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
     * Alerta de una fila del listado: sin control (no controla stock), sin stock, stock bajo
     * (en o bajo el mínimo) o normal.
     */
    public static function alerta(string $stock, string $minimo, bool $controlaStock = true): string
    {
        if (! $controlaStock) {
            return 'sin_control';
        }

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
            ->where('p.controla_stock', true)
            ->when($sucursalId !== null, fn ($q) => $q->where('s.id', $sucursalId))
            ->whereRaw('COALESCE(sn.stock_minimo, p.stock_minimo) > 0')
            ->whereRaw('COALESCE(sn.cantidad, 0) <= COALESCE(sn.stock_minimo, p.stock_minimo)')
            ->count();
    }
}
