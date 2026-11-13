<?php

namespace App\Domain\AgenteMozi\Herramientas;

use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\AgenteMozi\Datos\CatalogoDatos;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * analyze_pending_orders (UC-17): pedidos que siguen pendientes y cuántos se convierten en venta.
 * Un pedido pendiente no es venta ni reserva stock (D2): los faltantes comparan lo pedido en todos
 * los pendientes con el stock actual de su sucursal. Conversión = vendidos ÷ (vendidos + cancelados)
 * de los pedidos creados en el período. El vendedor solo ve su sucursal y no ve el resumen por
 * vendedor. El agente nunca confirma ni cancela pedidos.
 */
final class PedidosPendientes extends HerramientaNegocio
{
    private const NOMBRE_PRODUCTO = "CASE WHEN p.contenido IS NOT NULL THEN CONCAT(p.nombre, ' ', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM p.contenido)), ' ', u.abreviatura) ELSE p.nombre END";

    public function __construct(private readonly bool $administrador = false) {}

    public function nombre(): string
    {
        return 'analyze_pending_orders';
    }

    public function descripcion(): string
    {
        return 'Pedidos pendientes y conversión: resumen (pendientes y su valor, que NO es venta; antigüedad; conversión de los pedidos creados en el período, por defecto los últimos 30 días), '
            .'lista de pendientes con días abierto y líneas sin stock suficiente, y productos cuyo stock actual no cubre lo pedido en los pendientes (un pedido no reserva stock)'
            .($this->administrador ? ', y conversión por vendedor.' : '. Solo la sucursal del usuario.')
            .' El agente nunca confirma ni cancela pedidos: sugiere abrir el pedido.';
    }

    public function parametros(): array
    {
        return ['type' => 'object', 'properties' => $this->propiedadesPeriodo('hace 29 días (solo para la conversión)'), 'additionalProperties' => false];
    }

    public function reglas(): array
    {
        return $this->reglasPeriodo();
    }

    public function permiso(): ?string
    {
        return null;
    }

    public function avance(): string
    {
        return __('Revisando los pedidos pendientes…');
    }

    public function ejecutar(ContextoAgente $contexto, array $argumentos): array
    {
        $empresaId = $contexto->empresaId;
        $sucursal = $this->sucursalVendedor($contexto);
        $periodo = $this->periodo($contexto, $argumentos, 30);
        [$inicio, $fin] = $periodo->limitesUtc();
        $hoy = $contexto->fechaReferencia->format('Y-m-d');
        $desfase = $this->desfase($contexto);
        $creado = 'DATE('.$this->local('pe.created_at').')';
        $pedidos = fn () => DB::table('pedidos as pe')->where('pe.empresa_id', $empresaId)
            ->when($sucursal !== null, fn (Builder $q) => $q->where('pe.sucursal_id', $sucursal));
        $lineasPendientes = fn () => DB::table('pedido_items as pi')
            ->join('pedidos as pe', 'pe.id', '=', 'pi.pedido_id')
            ->join('productos as p', 'p.id', '=', 'pi.producto_id')
            ->leftJoin('stock_niveles as sn', fn (JoinClause $j) => $j->on('sn.empresa_id', '=', 'pi.empresa_id')->on('sn.sucursal_id', '=', 'pe.sucursal_id')->on('sn.producto_id', '=', 'pi.producto_id'))
            ->where('pi.empresa_id', $empresaId)
            ->where('pe.estado', 'pendiente')
            ->when($sucursal !== null, fn (Builder $q) => $q->where('pe.sucursal_id', $sucursal));
        // Un producto sin control de stock nunca falta: se vende sin stock.
        $sinStock = 'SUM(CASE WHEN p.controla_stock = 1 AND COALESCE(sn.cantidad, 0) < pi.cantidad THEN 1 ELSE 0 END)';

        $porPedido = $lineasPendientes()->groupBy('pi.pedido_id')
            ->select(['pi.pedido_id', DB::raw('COUNT(*) AS lineas'), DB::raw("{$sinStock} AS sin_stock")]);
        $pendientes = $pedidos()->where('pe.estado', 'pendiente')
            ->join('sucursales as s', 's.id', '=', 'pe.sucursal_id')
            ->leftJoin('users as vend', 'vend.id', '=', 'pe.vendedor_id')
            ->leftJoin('clientes as c', 'c.id', '=', 'pe.cliente_id')
            ->leftJoinSub($porPedido, 'l', 'l.pedido_id', '=', 'pe.id')
            ->orderBy('pe.created_at')->orderBy('pe.id')
            ->limit(500)
            ->select([DB::raw(CatalogoDatos::sqlCodigo('PED', 'pe.numero').' AS pedido'), 's.nombre as sucursal', 'vend.name as vendedor',
                DB::raw("COALESCE(c.nombre, 'Consumidor final') AS cliente"), 'pe.total', DB::raw('COALESCE(l.lineas, 0) AS lineas'),
                DB::raw('COALESCE(l.sin_stock, 0) AS lineas_sin_stock')])
            ->selectRaw("{$creado} AS fecha", [$desfase])
            ->selectRaw("DATEDIFF(?, {$creado}) AS dias_abierto", [$hoy, $desfase])
            ->get();

        $faltantes = $lineasPendientes()
            ->where('p.controla_stock', true)
            ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
            ->join('sucursales as s', 's.id', '=', 'pe.sucursal_id')
            ->groupBy('pi.producto_id', 'pe.sucursal_id')
            ->havingRaw('SUM(pi.cantidad) > MAX(COALESCE(sn.cantidad, 0))')
            ->orderByRaw('SUM(pi.cantidad) - MAX(COALESCE(sn.cantidad, 0)) DESC')
            ->limit(500)
            ->get([DB::raw('MAX('.self::NOMBRE_PRODUCTO.') AS producto'), DB::raw('MAX(s.nombre) AS sucursal'), DB::raw('SUM(pi.cantidad) AS pedido'),
                DB::raw('MAX(COALESCE(sn.cantidad, 0)) AS stock'), DB::raw('SUM(pi.cantidad) - MAX(COALESCE(sn.cantidad, 0)) AS faltante'),
                DB::raw('COUNT(DISTINCT pe.id) AS pedidos')]);

        $estados = $pedidos()->where('pe.created_at', '>=', $inicio)->where('pe.created_at', '<', $fin)->first([
            DB::raw('COUNT(*) AS creados'),
            DB::raw("COALESCE(SUM(CASE WHEN pe.estado = 'vendido' THEN 1 ELSE 0 END), 0) AS vendidos"),
            DB::raw("COALESCE(SUM(CASE WHEN pe.estado = 'cancelado' THEN 1 ELSE 0 END), 0) AS cancelados"),
            DB::raw("COALESCE(SUM(CASE WHEN pe.estado = 'pendiente' THEN 1 ELSE 0 END), 0) AS siguen_pendientes"),
        ]);
        $abiertos = $pedidos()->where('pe.estado', 'pendiente')
            ->select([DB::raw('COUNT(*) AS pendientes'), DB::raw('COALESCE(SUM(pe.total), 0) AS valor')])
            ->selectRaw("MAX(DATEDIFF(?, {$creado})) AS max_dias", [$hoy, $desfase])
            ->first();
        $meta = $this->meta($contexto, 'pedidos', $periodo, metodo: 'Pendientes a hoy; conversión de los pedidos creados en el período; stock actual por sucursal.');
        $dinero = fn (string $c, string $l) => $this->col($c, $l, 'money');

        $datasets = [
            $this->dataset('resumen', [
                $this->col('pendientes', __('Pedidos pendientes'), 'integer'), $dinero('valor_pendiente', __('Valor pendiente (no es venta)')),
                $this->col('max_dias_abierto', __('Pendiente más antiguo (días)'), 'integer'), $this->col('creados', __('Creados en el período'), 'integer'),
                $this->col('vendidos', __('Vendidos'), 'integer'), $this->col('cancelados', __('Cancelados'), 'integer'),
                $this->col('siguen_pendientes', __('Siguen pendientes'), 'integer'), $this->col('conversion_pct', __('Conversión'), 'percent'),
                $this->col('productos_con_faltante', __('Productos con faltante'), 'integer'),
            ], [[
                'pendientes' => $abiertos->pendientes ?? 0, 'valor_pendiente' => $abiertos->valor ?? '0', 'max_dias_abierto' => $abiertos->max_dias,
                'creados' => $estados->creados ?? 0, 'vendidos' => $estados->vendidos ?? 0, 'cancelados' => $estados->cancelados ?? 0,
                'siguen_pendientes' => $estados->siguen_pendientes ?? 0,
                'conversion_pct' => $this->porcentaje((string) ($estados->vendidos ?? 0), (string) ((int) ($estados->vendidos ?? 0) + (int) ($estados->cancelados ?? 0))),
                'productos_con_faltante' => $faltantes->count(),
            ]], $meta, 'Resumen (una fila). conversion_pct = vendidos ÷ (vendidos + cancelados) de los pedidos creados en el período; vacío si ninguno se decidió.'),
            $this->dataset('pendientes', [
                $this->col('pedido', __('Pedido'), 'string'), $this->col('fecha', __('Fecha'), 'date'), $this->col('dias_abierto', __('Días abierto'), 'integer'),
                $this->col('sucursal', __('Sucursal'), 'string'), $this->col('vendedor', __('Vendedor'), 'string'), $this->col('cliente', __('Cliente'), 'string'),
                $dinero('total', __('Total')), $this->col('lineas', __('Líneas'), 'integer'), $this->col('lineas_sin_stock', __('Líneas sin stock suficiente'), 'integer'),
            ], $pendientes, $meta, 'Pedidos pendientes del más antiguo al más reciente. lineas_sin_stock compara cada línea con el stock actual de la sucursal.'),
            $this->dataset('faltantes', [
                $this->col('producto', __('Producto'), 'string'), $this->col('sucursal', __('Sucursal'), 'string'), $this->col('pedido', __('Pedido en pendientes'), 'number'),
                $this->col('stock', __('Stock actual'), 'number'), $this->col('faltante', __('Faltante'), 'number'), $this->col('pedidos', __('Pedidos'), 'integer'),
            ], $faltantes, $meta, 'Productos cuyo stock actual en la sucursal no cubre la suma de lo pedido en sus pedidos pendientes.'),
        ];

        if ($sucursal === null) {
            $vendedores = $pedidos()->leftJoin('users as vend', 'vend.id', '=', 'pe.vendedor_id')
                ->where('pe.created_at', '>=', $inicio)->where('pe.created_at', '<', $fin)
                ->groupBy('pe.vendedor_id')
                ->orderByRaw('COUNT(*) DESC')
                ->get([DB::raw("COALESCE(MAX(vend.name), 'Sin vendedor') AS vendedor"), DB::raw('COUNT(*) AS creados'),
                    DB::raw("SUM(CASE WHEN pe.estado = 'vendido' THEN 1 ELSE 0 END) AS vendidos"),
                    DB::raw("SUM(CASE WHEN pe.estado = 'cancelado' THEN 1 ELSE 0 END) AS cancelados"),
                    DB::raw("SUM(CASE WHEN pe.estado = 'pendiente' THEN 1 ELSE 0 END) AS pendientes"),
                    DB::raw("SUM(CASE WHEN pe.estado = 'vendido' THEN pe.total ELSE 0 END) AS valor_vendido")])
                ->map(fn (object $v) => (array) $v + ['conversion_pct' => $this->porcentaje((string) $v->vendidos, (string) ((int) $v->vendidos + (int) $v->cancelados))]);

            $datasets[] = $this->dataset('por_vendedor', [
                $this->col('vendedor', __('Vendedor'), 'string'), $this->col('creados', __('Creados'), 'integer'), $this->col('vendidos', __('Vendidos'), 'integer'),
                $this->col('cancelados', __('Cancelados'), 'integer'), $this->col('pendientes', __('Pendientes'), 'integer'),
                $this->col('conversion_pct', __('Conversión'), 'percent'), $dinero('valor_vendido', __('Valor convertido en venta')),
            ], $vendedores, $meta, 'Pedidos creados en el período por vendedor y su conversión.');
        }

        return $datasets;
    }
}
