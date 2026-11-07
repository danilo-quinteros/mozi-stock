<?php

namespace App\Domain\AgenteMozi\Datos;

use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\AgenteMozi\Herramientas\ArgumentoInvalido;
use App\Domain\Creditos\Consultas\CuentasPorCobrar;
use App\Domain\CuentasPagar\Consultas\CuentasPorPagar;
use App\Enums\Rol;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Catálogo cerrado de la consulta genérica (docs/AGENTE_MOZI_IA.md § 5.1). Cada fuente arma su
 * consulta base con la empresa de la sesión y, para el vendedor, con su sucursal fija; los campos son
 * expresiones SQL fijas escritas aquí (literal-string), nunca por el modelo. «Hoy» y el desfase horario
 * salen de la fecha de referencia del turno, para que coincidan con el resto del sistema (UTC_TIMESTAMP
 * de MySQL no seguiría la hora de la aplicación), y llegan como parámetros en una subconsulta de una
 * fila unida a toda fuente: los campos los leen como referencia.hoy y referencia.desfase.
 *
 * Las definiciones del negocio son las de los módulos: venta = venta confirmada, cobrado = libro de
 * caja, cuentas por cobrar y por pagar desde sus consultas (sin anuladas), devoluciones en su fecha.
 */
final class CatalogoDatos
{
    public const FUENTES = [
        'productos', 'stock', 'ventas', 'productos_vendidos', 'pedidos', 'clientes', 'devoluciones', 'productos_devueltos',
        'compras', 'productos_comprados', 'proveedores', 'dinero', 'egresos', 'cuentas_por_cobrar', 'cuentas_por_pagar', 'movimientos_inventario',
    ];

    /** Fuentes del vendedor: su sucursal, sin costos, compras, gastos ni cuentas. */
    public const FUENTES_VENDEDOR = ['productos', 'stock', 'ventas', 'productos_vendidos', 'pedidos', 'clientes'];

    private const DIAS = "'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'";

    /** Nombre visible del producto, igual que en Inventario (requiere los alias p y u). */
    private const PRODUCTO = "CASE WHEN p.contenido IS NOT NULL THEN CONCAT(p.nombre, ' ', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM p.contenido)), ' ', u.abreviatura) ELSE p.nombre END";

    private readonly int $empresaId;

    /** Sucursal fija del vendedor; null = administrador (toda la empresa). */
    private readonly ?int $sucursalVendedor;

    private readonly string $desfase;

    private readonly string $hoy;

    public function __construct(ContextoAgente $contexto)
    {
        $this->empresaId = $contexto->empresaId;
        // Un vendedor sin sucursal asignada no ve filas de ninguna: 0 nunca es un id válido.
        $this->sucursalVendedor = $contexto->rol === Rol::Administrador ? null : ($contexto->sucursalId ?? 0);
        $this->desfase = $contexto->fechaReferencia->format('P');
        $this->hoy = $contexto->fechaReferencia->format('Y-m-d');

        if (preg_match('/^[+-]\d{2}:\d{2}$/', $this->desfase) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->hoy) !== 1) {
            throw new LogicException('Fecha de referencia inválida para la consulta.');
        }
    }

    /**
     * Catálogo solo para describir fuentes y campos al modelo: sin empresa, nunca se ejecuta.
     */
    public static function paraDescribir(bool $administrador): self
    {
        $zona = (string) config('mozistock.business_timezone');

        return new self(new ContextoAgente(0, 0, $administrador ? Rol::Administrador : Rol::Vendedor, null, $zona, CarbonImmutable::now($zona), 'es'));
    }

    public function administrador(): bool
    {
        return $this->sucursalVendedor === null;
    }

    public function sucursalVendedor(): ?int
    {
        return $this->sucursalVendedor;
    }

    public function desfase(): string
    {
        return $this->desfase;
    }

    public function hoy(): string
    {
        return $this->hoy;
    }

    /**
     * Consulta base de la fuente con la subconsulta referencia (hoy y desfase como parámetros), que
     * usan los campos con fechas locales o días transcurridos. Una fila constante: no cambia el plan.
     */
    public function consulta(Fuente $fuente): Builder
    {
        return ($fuente->base)()->crossJoinSub(
            DB::query()->selectRaw('CAST(? AS DATE) AS hoy, ? AS desfase', [$this->hoy, $this->desfase]),
            'referencia',
        );
    }

    /**
     * @return list<string>
     */
    public function fuentesPermitidas(): array
    {
        return $this->administrador() ? self::FUENTES : self::FUENTES_VENDEDOR;
    }

    public function fuente(string $nombre): Fuente
    {
        if (! in_array($nombre, $this->fuentesPermitidas(), true)) {
            throw new ArgumentoInvalido("La fuente {$nombre} no está disponible para el rol del usuario. Fuentes disponibles: ".implode(', ', $this->fuentesPermitidas()).'.');
        }

        return match ($nombre) {
            'productos' => $this->productos(),
            'stock' => $this->stock(),
            'ventas' => $this->ventas(),
            'productos_vendidos' => $this->productosVendidos(),
            'pedidos' => $this->pedidos(),
            'clientes' => $this->clientes(),
            'devoluciones' => $this->devoluciones(),
            'productos_devueltos' => $this->productosDevueltos(),
            'compras' => $this->compras(),
            'productos_comprados' => $this->productosComprados(),
            'proveedores' => $this->proveedores(),
            'dinero' => $this->dinero(),
            'egresos' => $this->egresos(),
            'cuentas_por_cobrar' => $this->cuentasPorCobrar(),
            'cuentas_por_pagar' => $this->cuentasPorPagar(),
            'movimientos_inventario' => $this->movimientosInventario(),
            default => throw new ArgumentoInvalido("La fuente {$nombre} no existe."),
        };
    }

    private function productos(): Fuente
    {
        $existencia = 'COALESCE(st.existencia, 0)';

        return new Fuente('productos', $this->administrador()
            ? 'Catálogo con precios y stock actual (suma de las sucursales activas). Sin fecha principal.'
            : 'Catálogo con precios y stock actual de tu sucursal. Sin fecha principal.',
            false,
            fn () => DB::table('productos as p')
                ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
                ->leftJoin('categorias as cat', 'cat.id', '=', 'p.categoria_id')
                ->leftJoinSub($this->conSucursal(DB::table('stock_niveles as sn')
                    ->join('sucursales as s', fn (JoinClause $j) => $j->on('s.id', '=', 'sn.sucursal_id')->where('s.activa', true))
                    ->where('sn.empresa_id', $this->empresaId), 'sn.sucursal_id')
                    ->groupBy('sn.producto_id')
                    ->select(['sn.producto_id', DB::raw('SUM(sn.cantidad) AS existencia')]), 'st', 'st.producto_id', '=', 'p.id')
                ->where('p.empresa_id', $this->empresaId),
            [
                $this->campo('nombre', __('Nombre'), 'string', 'p.nombre'),
                $this->campo('sku', __('SKU'), 'string', 'p.sku'),
                $this->campo('codigo_barras', __('Código de barras'), 'string', 'p.codigo_barras'),
                $this->campo('categoria', __('Categoría'), 'string', 'cat.nombre'),
                $this->campo('unidad', __('Unidad'), 'string', 'u.nombre'),
                $this->campo('contenido', __('Contenido'), 'number', 'p.contenido'),
                $this->campo('tipo_venta', __('Tipo de venta'), 'string', 'p.tipo_venta'),
                $this->campo('descripcion', __('Descripción'), 'string', 'LEFT(p.descripcion, 200)'),
                $this->campo('precio_consumidor', __('Precio consumidor'), 'money', 'p.precio_consumidor'),
                $this->campo('precio_minorista', __('Precio minorista'), 'money', 'p.precio_minorista'),
                $this->campo('precio_mayorista', __('Precio mayorista'), 'money', 'p.precio_mayorista'),
                $this->campo('stock', __('Stock'), 'number', $existencia),
                $this->campo('stock_minimo', __('Stock mínimo'), 'number', 'p.stock_minimo'),
                $this->campo('estado', __('Estado'), 'string', "CASE WHEN p.activo = 1 THEN 'activo' ELSE 'inactivo' END"),
                $this->campo('fecha_alta', __('Fecha de alta'), 'date', "DATE({$this->local('p.created_at')})"),
                Campo::medida('conteo', __('Productos'), 'integer', 'COUNT(*)'),
                Campo::medida('stock_total', __('Stock total'), 'number', "SUM({$existencia})"),
                Campo::medida('sin_stock', __('Sin stock'), 'integer', "SUM(CASE WHEN {$existencia} <= 0 THEN 1 ELSE 0 END)"),
                Campo::medida('precio_promedio', __('Precio promedio'), 'money', 'AVG(p.precio_consumidor)'),
                Campo::medida('precio_minimo', __('Precio mínimo'), 'money', 'MIN(p.precio_consumidor)'),
                Campo::medida('precio_maximo', __('Precio máximo'), 'money', 'MAX(p.precio_consumidor)'),
                Campo::medida('valor_stock_precio_venta', __('Stock a precio consumidor'), 'money', "SUM({$existencia} * p.precio_consumidor)"),
            ],
            ['nombre', 'sku', 'categoria', 'precio_consumidor', 'stock'],
            ['nombre', 'asc'],
            soloActivos: 'p.activo = 1',
        );
    }

    private function stock(): Fuente
    {
        $existencia = 'COALESCE(sn.cantidad, 0)';
        $minimo = 'COALESCE(sn.stock_minimo, p.stock_minimo)';
        $bajo = "{$existencia} > 0 AND {$minimo} > 0 AND {$existencia} <= {$minimo}";

        return new Fuente('stock', 'Existencia actual por producto y sucursal activa, con su mínimo y alerta (sin stock, bajo mínimo o normal). Sin fecha principal.',
            false,
            fn () => $this->conSucursal(DB::table('productos as p')
                ->join('sucursales as s', fn (JoinClause $j) => $j->on('s.empresa_id', '=', 'p.empresa_id')->where('s.activa', true))
                ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
                ->leftJoin('categorias as cat', 'cat.id', '=', 'p.categoria_id')
                ->leftJoin('stock_niveles as sn', fn (JoinClause $j) => $j->on('sn.empresa_id', '=', 'p.empresa_id')
                    ->on('sn.sucursal_id', '=', 's.id')->on('sn.producto_id', '=', 'p.id'))
                ->where('p.empresa_id', $this->empresaId), 's.id'),
            [
                $this->campo('producto', __('Producto'), 'string', self::PRODUCTO),
                $this->campo('sku', __('SKU'), 'string', 'p.sku'),
                $this->campo('categoria', __('Categoría'), 'string', 'cat.nombre'),
                $this->campo('sucursal', __('Sucursal'), 'string', 's.nombre'),
                $this->campo('existencia', __('Existencia'), 'number', $existencia),
                $this->campo('minimo', __('Mínimo'), 'number', $minimo),
                $this->campo('alerta', __('Alerta'), 'string', "CASE WHEN {$existencia} <= 0 THEN 'sin stock' WHEN {$bajo} THEN 'bajo mínimo' ELSE 'normal' END"),
                $this->campo('precio_consumidor', __('Precio consumidor'), 'money', 'p.precio_consumidor'),
                $this->campo('estado', __('Estado del producto'), 'string', "CASE WHEN p.activo = 1 THEN 'activo' ELSE 'inactivo' END"),
                Campo::medida('conteo', __('Registros'), 'integer', 'COUNT(*)'),
                Campo::medida('productos', __('Productos'), 'integer', 'COUNT(DISTINCT p.id)'),
                Campo::medida('existencia_total', __('Existencia total'), 'number', "SUM({$existencia})"),
                Campo::medida('sin_stock', __('Sin stock'), 'integer', "SUM(CASE WHEN {$existencia} <= 0 THEN 1 ELSE 0 END)"),
                Campo::medida('bajo_minimo', __('Bajo mínimo'), 'integer', "SUM(CASE WHEN {$bajo} THEN 1 ELSE 0 END)"),
                Campo::medida('valor_stock_precio_venta', __('Stock a precio consumidor'), 'money', "SUM({$existencia} * p.precio_consumidor)"),
            ],
            ['producto', 'sucursal', 'existencia', 'minimo', 'alerta'],
            ['producto', 'asc'],
            soloActivos: 'p.activo = 1',
        );
    }

    private function ventas(): Fuente
    {
        $cobrado = Venta::sqlCobrado('v');
        $descuento = '(v.descuento_lineas + v.descuento_global)';

        return new Fuente('ventas', 'Ventas confirmadas (un pedido pendiente no es venta). total = valor vendido, no dinero cobrado. condicion: contado o crédito. devuelto = lo devuelto después; las devoluciones de un período por su fecha están en devoluciones.'
            .($this->administrador() ? ' cobrado y saldo son a hoy; el dinero recibido por fecha está en dinero.' : ''),
            false,
            fn () => $this->conSucursal(DB::table('ventas as v')
                ->join('sucursales as s', 's.id', '=', 'v.sucursal_id')
                ->join('users as vend', 'vend.id', '=', 'v.vendedor_id')
                ->leftJoin('clientes as c', 'c.id', '=', 'v.cliente_id')
                ->where('v.empresa_id', $this->empresaId), 'v.sucursal_id'),
            [
                $this->campo('venta', __('Venta'), 'string', $this->codigo('V', 'v.numero'), orden: 'v.numero'),
                ...$this->fechas('v.fecha'),
                $this->campo('sucursal', __('Sucursal'), 'string', 's.nombre'),
                $this->campo('vendedor', __('Vendedor'), 'string', 'vend.name'),
                $this->campo('cliente', __('Cliente'), 'string', "COALESCE(c.nombre, 'Consumidor final')"),
                $this->campo('documento_cliente', __('Documento del cliente'), 'string', 'c.documento'),
                $this->campo('condicion', __('Condición'), 'string', "CASE WHEN v.cobrado_inicial < v.total THEN 'crédito' ELSE 'contado' END"),
                $this->campo('subtotal', __('Subtotal'), 'money', 'v.subtotal'),
                $this->campo('descuento', __('Descuento'), 'money', $descuento),
                $this->campo('total', __('Total'), 'money', 'v.total'),
                $this->campo('devuelto', __('Devuelto'), 'money', 'v.devuelto'),
                $this->campo('cobrado', __('Cobrado'), 'money', $cobrado, administrador: true),
                $this->campo('saldo', __('Saldo'), 'money', 'v.saldo_pendiente', administrador: true),
                $this->campo('vencimiento', __('Vencimiento'), 'date', 'v.vencimiento', administrador: true),
                Campo::medida('conteo', __('Ventas'), 'integer', 'COUNT(*)'),
                Campo::medida('valor_vendido', __('Valor vendido'), 'money', 'SUM(v.total)'),
                Campo::medida('ticket_promedio', __('Ticket promedio'), 'money', 'AVG(v.total)'),
                Campo::medida('total_descuentos', __('Descuentos'), 'money', "SUM({$descuento})"),
                Campo::medida('total_devuelto', __('Devuelto'), 'money', 'SUM(v.devuelto)'),
                Campo::medida('clientes_distintos', __('Clientes'), 'integer', 'COUNT(DISTINCT v.cliente_id)'),
                Campo::medida('total_cobrado', __('Cobrado'), 'money', "SUM({$cobrado})", true),
                Campo::medida('total_saldo', __('Saldo'), 'money', 'SUM(v.saldo_pendiente)', true),
            ],
            ['venta', 'fecha', 'sucursal', 'vendedor', 'cliente', 'total'],
            ['fecha', 'desc'],
            'v.fecha',
        );
    }

    private function productosVendidos(): Fuente
    {
        return new Fuente('productos_vendidos', 'Líneas de ventas confirmadas por producto. Fecha principal: fecha de la venta.',
            false,
            fn () => $this->conSucursal(DB::table('venta_items as vi')
                ->join('ventas as v', 'v.id', '=', 'vi.venta_id')
                ->join('productos as p', 'p.id', '=', 'vi.producto_id')
                ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
                ->leftJoin('categorias as cat', 'cat.id', '=', 'p.categoria_id')
                ->join('sucursales as s', 's.id', '=', 'v.sucursal_id')
                ->join('users as vend', 'vend.id', '=', 'v.vendedor_id')
                ->leftJoin('clientes as c', 'c.id', '=', 'v.cliente_id')
                ->where('vi.empresa_id', $this->empresaId), 'v.sucursal_id'),
            [
                $this->campo('venta', __('Venta'), 'string', $this->codigo('V', 'v.numero'), orden: 'v.numero'),
                ...$this->fechas('v.fecha'),
                $this->campo('producto', __('Producto'), 'string', self::PRODUCTO),
                $this->campo('sku', __('SKU'), 'string', 'p.sku'),
                $this->campo('categoria', __('Categoría'), 'string', 'cat.nombre'),
                $this->campo('sucursal', __('Sucursal'), 'string', 's.nombre'),
                $this->campo('vendedor', __('Vendedor'), 'string', 'vend.name'),
                $this->campo('cliente', __('Cliente'), 'string', "COALESCE(c.nombre, 'Consumidor final')"),
                $this->campo('cantidad', __('Cantidad'), 'number', 'vi.cantidad'),
                $this->campo('tipo_precio', __('Tipo de precio'), 'string', 'vi.tipo_precio'),
                $this->campo('precio_unitario', __('Precio unitario'), 'money', 'vi.precio_unitario'),
                $this->campo('descuento', __('Descuento'), 'money', 'vi.descuento'),
                $this->campo('total', __('Total'), 'money', 'vi.total'),
                Campo::medida('unidades', __('Unidades'), 'number', 'SUM(vi.cantidad)'),
                Campo::medida('importe', __('Importe'), 'money', 'SUM(vi.total)'),
                Campo::medida('ventas', __('Ventas'), 'integer', 'COUNT(DISTINCT vi.venta_id)'),
                Campo::medida('conteo', __('Líneas'), 'integer', 'COUNT(*)'),
                Campo::medida('productos_distintos', __('Productos'), 'integer', 'COUNT(DISTINCT vi.producto_id)'),
                Campo::medida('precio_promedio', __('Precio promedio'), 'money', 'SUM(vi.total) / NULLIF(SUM(vi.cantidad), 0)'),
            ],
            ['venta', 'fecha', 'producto', 'cantidad', 'precio_unitario', 'total'],
            ['fecha', 'desc'],
            'v.fecha',
        );
    }

    private function pedidos(): Fuente
    {
        $pendientes = self::contarEstado('pendiente');
        $vendidos = self::contarEstado('vendido');
        $cancelados = self::contarEstado('cancelado');

        return new Fuente('pedidos', 'Pedidos y cotizaciones. estado: pendiente, vendido o cancelado. Un pedido pendiente no es venta ni reserva stock. Fecha principal: creación del pedido.',
            false,
            fn () => $this->conSucursal(DB::table('pedidos as pe')
                ->join('sucursales as s', 's.id', '=', 'pe.sucursal_id')
                ->leftJoin('users as vend', 'vend.id', '=', 'pe.vendedor_id')
                ->leftJoin('clientes as c', 'c.id', '=', 'pe.cliente_id')
                ->leftJoin('ventas as v', fn (JoinClause $j) => $j->on('v.pedido_id', '=', 'pe.id')->on('v.empresa_id', '=', 'pe.empresa_id'))
                ->where('pe.empresa_id', $this->empresaId), 'pe.sucursal_id'),
            [
                $this->campo('pedido', __('Pedido'), 'string', $this->codigo('PED', 'pe.numero'), orden: 'pe.numero'),
                ...$this->fechas('pe.created_at'),
                $this->campo('estado', __('Estado'), 'string', 'pe.estado'),
                $this->campo('sucursal', __('Sucursal'), 'string', 's.nombre'),
                $this->campo('vendedor', __('Vendedor'), 'string', 'vend.name'),
                $this->campo('cliente', __('Cliente'), 'string', "COALESCE(c.nombre, 'Consumidor final')"),
                $this->campo('total', __('Total'), 'money', 'pe.total'),
                $this->campo('descuento', __('Descuento'), 'money', '(pe.descuento_lineas + pe.descuento_global)'),
                $this->campo('nota', __('Nota'), 'string', 'LEFT(pe.nota, 200)'),
                $this->campo('dias_abierto', __('Días abierto'), 'integer', "CASE WHEN pe.estado = 'pendiente' THEN DATEDIFF(referencia.hoy, DATE({$this->local('pe.created_at')})) END"),
                $this->campo('venta', __('Venta'), 'string', $this->codigo('V', 'v.numero')),
                $this->campo('fecha_cancelacion', __('Fecha de cancelación'), 'date', "DATE({$this->local('pe.cancelado_en')})"),
                Campo::medida('conteo', __('Pedidos'), 'integer', 'COUNT(*)'),
                Campo::medida('valor_total', __('Valor'), 'money', 'SUM(pe.total)'),
                Campo::medida('valor_promedio', __('Valor promedio'), 'money', 'AVG(pe.total)'),
                Campo::medida('pendientes', __('Pendientes'), 'integer', $pendientes),
                Campo::medida('vendidos', __('Vendidos'), 'integer', $vendidos),
                Campo::medida('cancelados', __('Cancelados'), 'integer', $cancelados),
                Campo::medida('valor_pendiente', __('Valor pendiente'), 'money', "SUM(CASE WHEN pe.estado = 'pendiente' THEN pe.total ELSE 0 END)"),
                Campo::medida('conversion_pct', __('Conversión'), 'percent', "100 * {$vendidos} / NULLIF({$vendidos} + {$cancelados}, 0)"),
            ],
            ['pedido', 'fecha', 'estado', 'sucursal', 'vendedor', 'cliente', 'total'],
            ['fecha', 'desc'],
            'pe.created_at',
        );
    }

    private function clientes(): Fuente
    {
        $compras = $this->conSucursal(DB::table('ventas')->where('empresa_id', $this->empresaId), 'sucursal_id')
            ->whereNotNull('cliente_id')
            ->groupBy('cliente_id')
            ->select(['cliente_id', DB::raw('COUNT(*) AS compras'), DB::raw('SUM(total) AS total'), DB::raw('MAX(fecha) AS ultima')]);

        return new Fuente('clientes', 'Directorio de clientes con sus compras históricas (ventas confirmadas'.($this->administrador() ? '' : ' de tu sucursal')
            .') y última compra. Para compras de un período usa ventas agrupadas por cliente. Sin fecha principal.',
            false,
            fn () => DB::table('clientes as c')
                ->leftJoinSub($compras, 'k', 'k.cliente_id', '=', 'c.id')
                ->when($this->administrador(), fn (Builder $q) => $q->leftJoinSub(CuentasPorCobrar::porCliente($this->empresaId)->reorder(), 'sd', 'sd.cliente_id', '=', 'c.id'))
                ->where('c.empresa_id', $this->empresaId),
            [
                $this->campo('nombre', __('Nombre'), 'string', 'c.nombre'),
                $this->campo('documento', __('Documento'), 'string', 'c.documento'),
                $this->campo('telefono', __('Teléfono'), 'string', 'c.telefono'),
                $this->campo('correo', __('Correo'), 'string', 'c.correo'),
                $this->campo('estado', __('Estado'), 'string', "CASE WHEN c.activo = 1 THEN 'activo' ELSE 'inactivo' END"),
                $this->campo('compras', __('Compras'), 'integer', 'COALESCE(k.compras, 0)'),
                $this->campo('total_comprado', __('Total comprado'), 'money', 'COALESCE(k.total, 0)'),
                $this->campo('ultima_compra', __('Última compra'), 'date', "DATE({$this->local('k.ultima')})"),
                $this->campo('dias_sin_comprar', __('Días sin comprar'), 'integer', "DATEDIFF(referencia.hoy, DATE({$this->local('k.ultima')}))"),
                $this->campo('fecha_alta', __('Fecha de alta'), 'date', "DATE({$this->local('c.created_at')})"),
                $this->campo('saldo', __('Saldo por cobrar'), 'money', 'COALESCE(sd.saldo, 0)', administrador: true),
                $this->campo('vencido', __('Vencido'), 'money', 'COALESCE(sd.vencido, 0)', administrador: true),
                Campo::medida('conteo', __('Clientes'), 'integer', 'COUNT(*)'),
                Campo::medida('con_compras', __('Con compras'), 'integer', 'SUM(CASE WHEN k.compras > 0 THEN 1 ELSE 0 END)'),
                Campo::medida('importe_comprado', __('Total comprado'), 'money', 'SUM(COALESCE(k.total, 0))'),
                Campo::medida('saldo_total', __('Saldo por cobrar'), 'money', 'SUM(COALESCE(sd.saldo, 0))', true),
                Campo::medida('vencido_total', __('Vencido'), 'money', 'SUM(COALESCE(sd.vencido, 0))', true),
            ],
            ['nombre', 'documento', 'telefono', 'compras', 'total_comprado', 'ultima_compra'],
            ['nombre', 'asc'],
            soloActivos: 'c.activo = 1',
        );
    }

    private function devoluciones(): Fuente
    {
        return new Fuente('devoluciones', 'Devoluciones de ventas en su propia fecha. importe = valor devuelto; reembolso = dinero que salió; deuda_reducida = crédito descontado sin salida de dinero.',
            true,
            fn () => DB::table('devoluciones as d')
                ->join('ventas as v', 'v.id', '=', 'd.venta_id')
                ->join('sucursales as s', 's.id', '=', 'd.sucursal_id')
                ->join('users as us', 'us.id', '=', 'd.usuario_id')
                ->leftJoin('clientes as c', 'c.id', '=', 'v.cliente_id')
                ->where('d.empresa_id', $this->empresaId),
            [
                $this->campo('devolucion', __('Devolución'), 'string', $this->codigo('DEV', 'd.numero'), orden: 'd.numero'),
                ...$this->fechas('d.fecha'),
                $this->campo('venta', __('Venta'), 'string', $this->codigo('V', 'v.numero')),
                $this->campo('fecha_venta', __('Fecha de la venta'), 'date', "DATE({$this->local('v.fecha')})"),
                $this->campo('sucursal', __('Sucursal'), 'string', 's.nombre'),
                $this->campo('registrado_por', __('Registrado por'), 'string', 'us.name'),
                $this->campo('cliente', __('Cliente'), 'string', "COALESCE(c.nombre, 'Consumidor final')"),
                $this->campo('motivo', __('Motivo'), 'string', 'LEFT(d.motivo, 200)'),
                $this->campo('importe', __('Importe'), 'money', 'd.importe'),
                $this->campo('reembolso', __('Reembolso'), 'money', 'd.reembolso'),
                $this->campo('deuda_reducida', __('Deuda reducida'), 'money', 'd.deuda_reducida'),
                $this->campo('reembolso_metodo', __('Medio del reembolso'), 'string', 'd.reembolso_metodo'),
                Campo::medida('conteo', __('Devoluciones'), 'integer', 'COUNT(*)'),
                Campo::medida('importe_total', __('Importe'), 'money', 'SUM(d.importe)'),
                Campo::medida('reembolsado', __('Reembolsado'), 'money', 'SUM(d.reembolso)'),
                Campo::medida('deuda_reducida_total', __('Deuda reducida'), 'money', 'SUM(d.deuda_reducida)'),
            ],
            ['devolucion', 'fecha', 'venta', 'cliente', 'motivo', 'importe'],
            ['fecha', 'desc'],
            'd.fecha',
        );
    }

    private function productosDevueltos(): Fuente
    {
        return new Fuente('productos_devueltos', 'Líneas de devoluciones por producto, en la fecha de la devolución.',
            true,
            fn () => DB::table('devolucion_items as di')
                ->join('devoluciones as d', 'd.id', '=', 'di.devolucion_id')
                ->join('ventas as v', 'v.id', '=', 'd.venta_id')
                ->join('productos as p', 'p.id', '=', 'di.producto_id')
                ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
                ->leftJoin('categorias as cat', 'cat.id', '=', 'p.categoria_id')
                ->join('sucursales as s', 's.id', '=', 'd.sucursal_id')
                ->where('di.empresa_id', $this->empresaId),
            [
                $this->campo('devolucion', __('Devolución'), 'string', $this->codigo('DEV', 'd.numero'), orden: 'd.numero'),
                ...$this->fechas('d.fecha'),
                $this->campo('venta', __('Venta'), 'string', $this->codigo('V', 'v.numero')),
                $this->campo('producto', __('Producto'), 'string', self::PRODUCTO),
                $this->campo('sku', __('SKU'), 'string', 'p.sku'),
                $this->campo('categoria', __('Categoría'), 'string', 'cat.nombre'),
                $this->campo('sucursal', __('Sucursal'), 'string', 's.nombre'),
                $this->campo('motivo', __('Motivo'), 'string', 'LEFT(d.motivo, 200)'),
                $this->campo('cantidad', __('Cantidad'), 'number', 'di.cantidad'),
                $this->campo('importe', __('Importe'), 'money', 'di.importe'),
                $this->campo('reingresa_stock', __('Reingresa al stock'), 'string', "CASE WHEN di.reingresa_stock = 1 THEN 'sí' ELSE 'no' END"),
                Campo::medida('conteo', __('Líneas'), 'integer', 'COUNT(*)'),
                Campo::medida('unidades', __('Unidades'), 'number', 'SUM(di.cantidad)'),
                Campo::medida('importe_total', __('Importe'), 'money', 'SUM(di.importe)'),
                Campo::medida('devoluciones', __('Devoluciones'), 'integer', 'COUNT(DISTINCT di.devolucion_id)'),
            ],
            ['devolucion', 'fecha', 'producto', 'cantidad', 'importe', 'motivo'],
            ['fecha', 'desc'],
            'd.fecha',
        );
    }

    private function compras(): Fuente
    {
        $pagado = 'CASE WHEN cp.id IS NULL THEN co.pagado ELSE co.total - cp.saldo_pendiente END';
        $saldo = 'COALESCE(cp.saldo_pendiente, 0)';

        return new Fuente('compras', 'Compras de mercadería (no son gastos operativos). pagado y saldo son a hoy.',
            true,
            fn () => DB::table('compras as co')
                ->join('sucursales as s', 's.id', '=', 'co.sucursal_id')
                ->join('users as us', 'us.id', '=', 'co.usuario_id')
                ->leftJoin('proveedores as pr', 'pr.id', '=', 'co.proveedor_id')
                ->leftJoin('cuentas_pagar as cp', fn (JoinClause $j) => $j->on('cp.compra_id', '=', 'co.id')->on('cp.empresa_id', '=', 'co.empresa_id')->whereNull('cp.anulado_at'))
                ->where('co.empresa_id', $this->empresaId),
            [
                $this->campo('compra', __('Compra'), 'string', $this->codigo('COM', 'co.numero'), orden: 'co.numero'),
                ...$this->fechas('co.fecha'),
                $this->campo('sucursal', __('Sucursal'), 'string', 's.nombre'),
                $this->campo('proveedor', __('Proveedor'), 'string', "COALESCE(pr.nombre, 'Sin proveedor')"),
                $this->campo('registrado_por', __('Registrado por'), 'string', 'us.name'),
                $this->campo('referencia', __('Referencia'), 'string', 'co.referencia'),
                $this->campo('condicion', __('Condición'), 'string', 'co.condicion'),
                $this->campo('metodo', __('Medio de pago'), 'string', 'co.metodo'),
                $this->campo('total', __('Total'), 'money', 'co.total'),
                $this->campo('pagado', __('Pagado'), 'money', $pagado),
                $this->campo('saldo', __('Saldo'), 'money', $saldo),
                $this->campo('vencimiento', __('Vencimiento'), 'date', 'COALESCE(cp.vencimiento, co.vencimiento)'),
                Campo::medida('conteo', __('Compras'), 'integer', 'COUNT(*)'),
                Campo::medida('total_comprado', __('Total comprado'), 'money', 'SUM(co.total)'),
                Campo::medida('total_pagado', __('Pagado'), 'money', "SUM({$pagado})"),
                Campo::medida('saldo_total', __('Saldo'), 'money', "SUM({$saldo})"),
                Campo::medida('compra_promedio', __('Compra promedio'), 'money', 'AVG(co.total)'),
                Campo::medida('proveedores_distintos', __('Proveedores'), 'integer', 'COUNT(DISTINCT co.proveedor_id)'),
            ],
            ['compra', 'fecha', 'proveedor', 'sucursal', 'total', 'saldo'],
            ['fecha', 'desc'],
            'co.fecha',
        );
    }

    private function productosComprados(): Fuente
    {
        return new Fuente('productos_comprados', 'Líneas de compras por producto con su costo unitario.',
            true,
            fn () => DB::table('compra_items as ci')
                ->join('compras as co', 'co.id', '=', 'ci.compra_id')
                ->join('productos as p', 'p.id', '=', 'ci.producto_id')
                ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
                ->leftJoin('categorias as cat', 'cat.id', '=', 'p.categoria_id')
                ->join('sucursales as s', 's.id', '=', 'co.sucursal_id')
                ->leftJoin('proveedores as pr', 'pr.id', '=', 'co.proveedor_id')
                ->where('ci.empresa_id', $this->empresaId),
            [
                $this->campo('compra', __('Compra'), 'string', $this->codigo('COM', 'co.numero'), orden: 'co.numero'),
                ...$this->fechas('co.fecha'),
                $this->campo('producto', __('Producto'), 'string', self::PRODUCTO),
                $this->campo('sku', __('SKU'), 'string', 'p.sku'),
                $this->campo('categoria', __('Categoría'), 'string', 'cat.nombre'),
                $this->campo('sucursal', __('Sucursal'), 'string', 's.nombre'),
                $this->campo('proveedor', __('Proveedor'), 'string', "COALESCE(pr.nombre, 'Sin proveedor')"),
                $this->campo('cantidad', __('Cantidad'), 'number', 'ci.cantidad'),
                $this->campo('costo_unitario', __('Costo unitario'), 'money', 'ci.costo_unitario'),
                $this->campo('subtotal', __('Subtotal'), 'money', 'ci.subtotal'),
                Campo::medida('conteo', __('Líneas'), 'integer', 'COUNT(*)'),
                Campo::medida('unidades', __('Unidades'), 'number', 'SUM(ci.cantidad)'),
                Campo::medida('importe', __('Importe'), 'money', 'SUM(ci.subtotal)'),
                Campo::medida('compras', __('Compras'), 'integer', 'COUNT(DISTINCT ci.compra_id)'),
                Campo::medida('costo_promedio', __('Costo promedio'), 'money', 'SUM(ci.subtotal) / NULLIF(SUM(ci.cantidad), 0)'),
                Campo::medida('costo_minimo', __('Costo mínimo'), 'money', 'MIN(ci.costo_unitario)'),
                Campo::medida('costo_maximo', __('Costo máximo'), 'money', 'MAX(ci.costo_unitario)'),
            ],
            ['compra', 'fecha', 'producto', 'proveedor', 'cantidad', 'costo_unitario', 'subtotal'],
            ['fecha', 'desc'],
            'co.fecha',
        );
    }

    private function proveedores(): Fuente
    {
        $compras = DB::table('compras')->where('empresa_id', $this->empresaId)->whereNotNull('proveedor_id')
            ->groupBy('proveedor_id')
            ->select(['proveedor_id', DB::raw('COUNT(*) AS compras'), DB::raw('SUM(total) AS total'), DB::raw('MAX(fecha) AS ultima')]);
        $deudas = DB::table('cuentas_pagar')->where('empresa_id', $this->empresaId)->whereNull('anulado_at')
            ->groupBy('proveedor_id')
            ->select(['proveedor_id', DB::raw('SUM(saldo_pendiente) AS saldo')])
            ->selectRaw('SUM(CASE WHEN saldo_pendiente > 0 AND vencimiento < ? THEN saldo_pendiente ELSE 0 END) AS vencido', [$this->hoy]);

        return new Fuente('proveedores', 'Directorio de proveedores y acreedores con sus compras y el saldo por pagar actual. Sin fecha principal.',
            true,
            fn () => DB::table('proveedores as pr')
                ->leftJoinSub($compras, 'k', 'k.proveedor_id', '=', 'pr.id')
                ->leftJoinSub($deudas, 'sd', 'sd.proveedor_id', '=', 'pr.id')
                ->where('pr.empresa_id', $this->empresaId),
            [
                $this->campo('nombre', __('Nombre'), 'string', 'pr.nombre'),
                $this->campo('documento', __('Documento'), 'string', 'pr.documento'),
                $this->campo('telefono', __('Teléfono'), 'string', 'pr.telefono'),
                $this->campo('correo', __('Correo'), 'string', 'pr.correo'),
                $this->campo('ciudad', __('Ciudad'), 'string', 'pr.ciudad'),
                $this->campo('estado', __('Estado'), 'string', "CASE WHEN pr.activo = 1 THEN 'activo' ELSE 'inactivo' END"),
                $this->campo('compras', __('Compras'), 'integer', 'COALESCE(k.compras, 0)'),
                $this->campo('total_comprado', __('Total comprado'), 'money', 'COALESCE(k.total, 0)'),
                $this->campo('ultima_compra', __('Última compra'), 'date', "DATE({$this->local('k.ultima')})"),
                $this->campo('saldo_por_pagar', __('Saldo por pagar'), 'money', 'COALESCE(sd.saldo, 0)'),
                $this->campo('vencido', __('Vencido'), 'money', 'COALESCE(sd.vencido, 0)'),
                Campo::medida('conteo', __('Proveedores'), 'integer', 'COUNT(*)'),
                Campo::medida('importe_comprado', __('Total comprado'), 'money', 'SUM(COALESCE(k.total, 0))'),
                Campo::medida('saldo_total', __('Saldo por pagar'), 'money', 'SUM(COALESCE(sd.saldo, 0))'),
                Campo::medida('vencido_total', __('Vencido'), 'money', 'SUM(COALESCE(sd.vencido, 0))'),
            ],
            ['nombre', 'documento', 'telefono', 'compras', 'total_comprado', 'saldo_por_pagar'],
            ['nombre', 'asc'],
            soloActivos: 'pr.activo = 1',
        );
    }

    private function dinero(): Fuente
    {
        $origen = fn (string $alias, string ...$tipos) => fn (JoinClause $j) => $j->on("{$alias}.id", '=', 'm.origen_id')->whereIn('m.origen_tipo', $tipos);
        $entradas = "SUM(CASE WHEN m.sentido = 'ingreso' THEN m.monto ELSE 0 END)";
        $salidas = "SUM(CASE WHEN m.sentido = 'egreso' THEN m.monto ELSE 0 END)";

        return new Fuente('dinero', 'Libro de caja: cada entrada o salida real de dinero en su fecha (cobro al vender, abonos, compras, gastos, reembolsos, pagos a proveedores y anulaciones), una fila por medio de pago. Es la única fuente para «cuánto cobré o pagué».',
            true,
            fn () => DB::table('movimientos_caja as m')
                ->join('sucursales as s', 's.id', '=', 'm.sucursal_id')
                ->join('users as us', 'us.id', '=', 'm.usuario_id')
                ->leftJoin('cajas as cj', 'cj.id', '=', 'm.caja_id')
                ->leftJoin('pagos_venta as pv', $origen('pv', 'pago_venta'))
                ->leftJoin('ventas as vp', 'vp.id', '=', 'pv.venta_id')
                ->leftJoin('cobros as co', $origen('co', 'cobro', 'anulacion_cobro'))
                ->leftJoin('compras as cm', $origen('cm', 'compra'))
                ->leftJoin('egresos as eg', $origen('eg', 'egreso'))
                ->leftJoin('categorias_egreso as ce', 'ce.id', '=', 'eg.categoria_egreso_id')
                ->leftJoin('devoluciones as dv', $origen('dv', 'devolucion'))
                ->leftJoin('ventas as vd', 'vd.id', '=', 'dv.venta_id')
                ->leftJoin('pagos_cuenta_pagar as pc', $origen('pc', 'pago_cxp', 'anulacion_pago_cxp'))
                ->leftJoin('cuentas_pagar as cp', 'cp.id', '=', 'pc.cuenta_pagar_id')
                ->leftJoin('clientes as cl', 'cl.id', '=', DB::raw('COALESCE(vp.cliente_id, co.cliente_id, vd.cliente_id)'))
                ->leftJoin('proveedores as pr', 'pr.id', '=', DB::raw('COALESCE(cm.proveedor_id, eg.proveedor_id, cp.proveedor_id)'))
                ->where('m.empresa_id', $this->empresaId),
            [
                ...$this->fechas('m.fecha_operacion'),
                $this->campo('sentido', __('Sentido'), 'string', "CASE WHEN m.sentido = 'ingreso' THEN 'entrada' ELSE 'salida' END"),
                $this->campo('metodo', __('Medio'), 'string', "CASE WHEN m.metodo = 'efectivo' THEN 'efectivo' ELSE 'electrónico' END"),
                $this->campo('concepto', __('Concepto'), 'string', "CASE m.origen_tipo
                    WHEN 'pago_venta' THEN CASE WHEN pv.tipo <> 'inicial' THEN 'abono' WHEN vp.cobrado_inicial >= vp.total THEN 'venta al contado' ELSE 'cuota inicial' END
                    WHEN 'cobro' THEN 'abono' WHEN 'anulacion_cobro' THEN 'anulación de cobro' WHEN 'compra' THEN 'compra' WHEN 'egreso' THEN 'gasto'
                    WHEN 'devolucion' THEN 'reembolso' WHEN 'pago_cxp' THEN 'pago de cuenta por pagar' WHEN 'anulacion_pago_cxp' THEN 'anulación de pago' ELSE 'otro' END"),
                $this->campo('documento', __('Documento'), 'string', "CASE
                    WHEN m.origen_tipo = 'pago_venta' THEN {$this->codigo('V', 'vp.numero')}
                    WHEN m.origen_tipo IN ('cobro', 'anulacion_cobro') THEN {$this->codigo('COB', 'co.numero')}
                    WHEN m.origen_tipo = 'compra' THEN {$this->codigo('COM', 'cm.numero')}
                    WHEN m.origen_tipo = 'egreso' THEN {$this->codigo('EGR', 'eg.numero')}
                    WHEN m.origen_tipo = 'devolucion' THEN {$this->codigo('DEV', 'dv.numero')}
                    WHEN m.origen_tipo IN ('pago_cxp', 'anulacion_pago_cxp') THEN {$this->codigo('CXP', 'cp.numero')} END"),
                $this->campo('cliente', __('Cliente'), 'string', 'cl.nombre'),
                $this->campo('proveedor', __('Proveedor'), 'string', 'pr.nombre'),
                $this->campo('categoria_gasto', __('Categoría del gasto'), 'string', 'ce.nombre'),
                $this->campo('sucursal', __('Sucursal'), 'string', 's.nombre'),
                $this->campo('caja', __('Caja'), 'string', "COALESCE(cj.nombre, 'Fuera de caja')"),
                $this->campo('usuario', __('Usuario'), 'string', 'us.name'),
                $this->campo('monto', __('Monto'), 'money', 'm.monto'),
                $this->campo('monto_con_signo', __('Monto con signo'), 'money', "CASE WHEN m.sentido = 'ingreso' THEN m.monto ELSE -m.monto END"),
                Campo::medida('entradas', __('Entradas'), 'money', $entradas),
                Campo::medida('salidas', __('Salidas'), 'money', $salidas),
                Campo::medida('neto', __('Neto'), 'money', "{$entradas} - {$salidas}"),
                Campo::medida('operaciones', __('Operaciones'), 'integer', 'COUNT(DISTINCT m.origen_tipo, m.origen_id)'),
                Campo::medida('conteo', __('Movimientos'), 'integer', 'COUNT(*)'),
            ],
            ['fecha', 'hora', 'sentido', 'concepto', 'documento', 'metodo', 'monto', 'sucursal'],
            ['fecha', 'desc'],
            'm.fecha_operacion',
        );
    }

    private function egresos(): Fuente
    {
        $pagado = 'CASE WHEN cp.id IS NULL THEN eg.pagado ELSE eg.monto - cp.saldo_pendiente END';
        $saldo = 'COALESCE(cp.saldo_pendiente, 0)';

        return new Fuente('egresos', 'Gastos operativos (alquiler, servicios, sueldos…); no incluye compras de mercadería. pagado y saldo son a hoy.',
            true,
            fn () => DB::table('egresos as eg')
                ->join('sucursales as s', 's.id', '=', 'eg.sucursal_id')
                ->join('users as us', 'us.id', '=', 'eg.usuario_id')
                ->leftJoin('categorias_egreso as ce', 'ce.id', '=', 'eg.categoria_egreso_id')
                ->leftJoin('proveedores as pr', 'pr.id', '=', 'eg.proveedor_id')
                ->leftJoin('cuentas_pagar as cp', fn (JoinClause $j) => $j->on('cp.egreso_id', '=', 'eg.id')->on('cp.empresa_id', '=', 'eg.empresa_id')->whereNull('cp.anulado_at'))
                ->where('eg.empresa_id', $this->empresaId),
            [
                $this->campo('egreso', __('Egreso'), 'string', $this->codigo('EGR', 'eg.numero'), orden: 'eg.numero'),
                ...$this->fechas('eg.fecha'),
                $this->campo('descripcion', __('Descripción'), 'string', 'eg.descripcion'),
                $this->campo('categoria', __('Categoría'), 'string', "COALESCE(ce.nombre, 'Sin categoría')"),
                $this->campo('beneficiario', __('Beneficiario'), 'string', 'pr.nombre'),
                $this->campo('sucursal', __('Sucursal'), 'string', 's.nombre'),
                $this->campo('registrado_por', __('Registrado por'), 'string', 'us.name'),
                $this->campo('condicion', __('Condición'), 'string', 'eg.condicion'),
                $this->campo('metodo', __('Medio de pago'), 'string', 'eg.metodo'),
                $this->campo('monto', __('Monto'), 'money', 'eg.monto'),
                $this->campo('pagado', __('Pagado'), 'money', $pagado),
                $this->campo('saldo', __('Saldo'), 'money', $saldo),
                $this->campo('vencimiento', __('Vencimiento'), 'date', 'COALESCE(cp.vencimiento, eg.vencimiento)'),
                Campo::medida('conteo', __('Egresos'), 'integer', 'COUNT(*)'),
                Campo::medida('total_gastado', __('Total gastado'), 'money', 'SUM(eg.monto)'),
                Campo::medida('total_pagado', __('Pagado'), 'money', "SUM({$pagado})"),
                Campo::medida('saldo_total', __('Saldo'), 'money', "SUM({$saldo})"),
                Campo::medida('gasto_promedio', __('Gasto promedio'), 'money', 'AVG(eg.monto)'),
            ],
            ['egreso', 'fecha', 'categoria', 'descripcion', 'beneficiario', 'monto', 'saldo'],
            ['fecha', 'desc'],
            'eg.fecha',
        );
    }

    private function cuentasPorCobrar(): Fuente
    {
        $vencida = 'cxc.saldo > 0 AND cxc.vencimiento < referencia.hoy';

        return new Fuente('cuentas_por_cobrar', 'Cuentas por cobrar actuales: ventas a crédito y cuentas manuales, sin anuladas (las mismas cifras del módulo). estado: pendiente, parcial, vencida o pagada. Fecha principal: emisión. En ventas con cuotas, vencimiento es el de la cuota pendiente más antigua y vencido la suma de las cuotas vencidas.',
            true,
            fn () => DB::query()->fromSub(CuentasPorCobrar::consulta($this->empresaId)->reorder(), 'cxc'),
            [
                $this->campo('documento', __('Documento'), 'string', "CASE WHEN cxc.tipo = 'venta' THEN {$this->codigo('V', 'cxc.numero')} ELSE {$this->codigo('CXC', 'cxc.numero')} END"),
                $this->campo('tipo', __('Tipo'), 'string', "CASE WHEN cxc.tipo = 'venta' THEN 'venta a crédito' ELSE 'cuenta manual' END"),
                ...$this->fechas('cxc.fecha', false),
                $this->campo('cliente', __('Cliente'), 'string', "COALESCE(cxc.cliente, 'Consumidor final')"),
                $this->campo('documento_cliente', __('Documento del cliente'), 'string', 'cxc.documento_cliente'),
                $this->campo('concepto', __('Concepto'), 'string', 'cxc.concepto'),
                $this->campo('sucursal', __('Sucursal'), 'string', 'cxc.sucursal'),
                $this->campo('vencimiento', __('Vencimiento'), 'date', 'cxc.vencimiento'),
                $this->campo('importe', __('Importe'), 'money', 'cxc.importe'),
                $this->campo('cobrado', __('Cobrado'), 'money', 'cxc.cobrado'),
                $this->campo('saldo', __('Saldo'), 'money', 'cxc.saldo'),
                $this->campo('vencido', __('Vencido'), 'money', 'cxc.vencido'),
                $this->campo('estado', __('Estado'), 'string', "CASE WHEN cxc.saldo <= 0 THEN 'pagada' WHEN {$vencida} THEN 'vencida' WHEN cxc.cobrado > 0 THEN 'parcial' ELSE 'pendiente' END"),
                $this->campo('dias_vencido', __('Días de atraso'), 'integer', "CASE WHEN {$vencida} THEN DATEDIFF(referencia.hoy, cxc.vencimiento) ELSE 0 END"),
                $this->campo('cuotas_pendientes', __('Cuotas pendientes'), 'integer', 'cxc.cuotas_pendientes'),
                Campo::medida('conteo', __('Cuentas'), 'integer', 'COUNT(*)'),
                Campo::medida('importe_total', __('Importe'), 'money', 'SUM(cxc.importe)'),
                Campo::medida('cobrado_total', __('Cobrado'), 'money', 'SUM(cxc.cobrado)'),
                Campo::medida('saldo_total', __('Saldo'), 'money', 'SUM(cxc.saldo)'),
                Campo::medida('vencido_total', __('Vencido'), 'money', 'SUM(cxc.vencido)'),
                Campo::medida('clientes_distintos', __('Clientes'), 'integer', 'COUNT(DISTINCT cxc.cliente_id)'),
            ],
            ['documento', 'cliente', 'fecha', 'vencimiento', 'importe', 'saldo', 'estado'],
            ['saldo', 'desc'],
            'cxc.fecha',
            false,
        );
    }

    private function cuentasPorPagar(): Fuente
    {
        $vencida = 'cxp.saldo_pendiente > 0 AND cxp.vencimiento < referencia.hoy';

        return new Fuente('cuentas_por_pagar', 'Cuentas por pagar actuales a proveedores y acreedores (compras, egresos y manuales), sin anuladas: las mismas cifras del módulo. estado: pendiente, parcial, vencida o pagada. dias_para_vencer negativo = días de atraso. Fecha principal: emisión.',
            true,
            fn () => DB::query()->fromSub(CuentasPorPagar::consulta($this->empresaId)->reorder(), 'cxp'),
            [
                $this->campo('cuenta', __('Cuenta'), 'string', $this->codigo('CXP', 'cxp.numero'), orden: 'cxp.numero'),
                $this->campo('origen', __('Origen'), 'string', 'cxp.origen'),
                $this->campo('documento_origen', __('Documento de origen'), 'string', "CASE cxp.origen WHEN 'compra' THEN {$this->codigo('COM', 'cxp.compra_numero')} WHEN 'egreso' THEN {$this->codigo('EGR', 'cxp.egreso_numero')} ELSE 'manual' END"),
                $this->campo('acreedor', __('Acreedor'), 'string', 'cxp.proveedor'),
                $this->campo('concepto', __('Concepto'), 'string', 'cxp.concepto'),
                $this->campo('sucursal', __('Sucursal'), 'string', 'cxp.sucursal'),
                ...$this->fechas('cxp.fecha', false),
                $this->campo('vencimiento', __('Vencimiento'), 'date', 'cxp.vencimiento'),
                $this->campo('total', __('Total'), 'money', 'cxp.total_documento'),
                $this->campo('pagado', __('Pagado'), 'money', 'cxp.pagado'),
                $this->campo('saldo', __('Saldo'), 'money', 'cxp.saldo_pendiente'),
                $this->campo('estado', __('Estado'), 'string', "CASE WHEN cxp.saldo_pendiente <= 0 THEN 'pagada' WHEN {$vencida} THEN 'vencida' WHEN cxp.pagado > 0 THEN 'parcial' ELSE 'pendiente' END"),
                $this->campo('dias_para_vencer', __('Días para vencer'), 'integer', 'CASE WHEN cxp.saldo_pendiente > 0 THEN DATEDIFF(cxp.vencimiento, referencia.hoy) END'),
                Campo::medida('conteo', __('Cuentas'), 'integer', 'COUNT(*)'),
                Campo::medida('total_documentos', __('Total'), 'money', 'SUM(cxp.total_documento)'),
                Campo::medida('pagado_total', __('Pagado'), 'money', 'SUM(cxp.pagado)'),
                Campo::medida('saldo_total', __('Saldo'), 'money', 'SUM(cxp.saldo_pendiente)'),
                Campo::medida('vencido_total', __('Vencido'), 'money', "SUM(CASE WHEN {$vencida} THEN cxp.saldo_pendiente ELSE 0 END)"),
                Campo::medida('acreedores_distintos', __('Acreedores'), 'integer', 'COUNT(DISTINCT cxp.proveedor)'),
            ],
            ['cuenta', 'acreedor', 'concepto', 'vencimiento', 'total', 'saldo', 'estado'],
            ['vencimiento', 'asc'],
            'cxp.fecha',
            false,
        );
    }

    private function movimientosInventario(): Fuente
    {
        return new Fuente('movimientos_inventario', 'Movimientos de stock por producto y sucursal: cantidad positiva entra y negativa sale. tipo: venta, compra, entrada, salida, ajuste, traspaso o devolucion.',
            true,
            fn () => DB::table('movimientos_stock as ms')
                ->join('operaciones_inventario as o', 'o.id', '=', 'ms.operacion_id')
                ->join('productos as p', 'p.id', '=', 'ms.producto_id')
                ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
                ->leftJoin('categorias as cat', 'cat.id', '=', 'p.categoria_id')
                ->join('sucursales as s', 's.id', '=', 'ms.sucursal_id')
                ->leftJoin('sucursales as sd', 'sd.id', '=', 'o.sucursal_destino_id')
                ->join('users as us', 'us.id', '=', 'o.usuario_id')
                ->where('ms.empresa_id', $this->empresaId),
            [
                $this->campo('operacion', __('Operación'), 'string', "CONCAT(o.serie, '-', IF(o.numero < 100000, LPAD(o.numero, 5, '0'), o.numero))"),
                ...$this->fechas('ms.created_at'),
                $this->campo('tipo', __('Tipo'), 'string', 'o.tipo'),
                $this->campo('motivo', __('Motivo'), 'string', 'o.motivo'),
                $this->campo('producto', __('Producto'), 'string', self::PRODUCTO),
                $this->campo('sku', __('SKU'), 'string', 'p.sku'),
                $this->campo('categoria', __('Categoría'), 'string', 'cat.nombre'),
                $this->campo('sucursal', __('Sucursal'), 'string', 's.nombre'),
                $this->campo('sucursal_destino', __('Sucursal destino'), 'string', 'sd.nombre'),
                $this->campo('usuario', __('Usuario'), 'string', 'us.name'),
                $this->campo('nota', __('Nota'), 'string', 'LEFT(o.nota, 200)'),
                $this->campo('cantidad', __('Cantidad'), 'number', 'ms.cantidad'),
                $this->campo('saldo_anterior', __('Saldo anterior'), 'number', 'ms.saldo_anterior'),
                $this->campo('saldo_posterior', __('Saldo posterior'), 'number', 'ms.saldo_posterior'),
                Campo::medida('conteo', __('Movimientos'), 'integer', 'COUNT(*)'),
                Campo::medida('operaciones', __('Operaciones'), 'integer', 'COUNT(DISTINCT ms.operacion_id)'),
                Campo::medida('entradas', __('Entradas'), 'number', 'SUM(CASE WHEN ms.cantidad > 0 THEN ms.cantidad ELSE 0 END)'),
                Campo::medida('salidas', __('Salidas'), 'number', 'SUM(CASE WHEN ms.cantidad < 0 THEN -ms.cantidad ELSE 0 END)'),
                Campo::medida('neto', __('Neto'), 'number', 'SUM(ms.cantidad)'),
            ],
            ['operacion', 'fecha', 'tipo', 'producto', 'sucursal', 'cantidad', 'saldo_posterior'],
            ['fecha', 'desc'],
            'ms.created_at',
        );
    }

    /**
     * Fecha principal en hora de La Paz y sus derivados para agrupar: fecha, semana (lunes), mes,
     * año, día de la semana y, si la columna tiene hora, la hora.
     *
     * @param  literal-string  $columna
     * @return list<Campo>
     */
    private function fechas(string $columna, bool $conHora = true): array
    {
        $local = $conHora ? $this->local($columna) : $columna;
        $campos = [
            $this->campo('fecha', __('Fecha'), 'date', "DATE({$local})", orden: $columna),
            $this->campo('semana', __('Semana (lunes)'), 'date', "DATE(DATE_SUB({$local}, INTERVAL WEEKDAY({$local}) DAY))"),
            $this->campo('mes', __('Mes'), 'string', "DATE_FORMAT({$local}, '%Y-%m')"),
            $this->campo('anio', __('Año'), 'integer', "YEAR({$local})"),
            $this->campo('dia_semana', __('Día de la semana'), 'string', "ELT(WEEKDAY({$local}) + 1, ".self::DIAS.')', orden: "WEEKDAY({$local})"),
        ];

        if ($conHora) {
            $campos[] = $this->campo('hora', __('Hora'), 'integer', "HOUR({$local})");
        }

        return $campos;
    }

    /**
     * Columna UTC en hora local, con el desfase del turno tomado de la subconsulta referencia.
     *
     * @param  literal-string  $columna
     * @return literal-string
     */
    private function local(string $columna): string
    {
        return "CONVERT_TZ({$columna}, '+00:00', referencia.desfase)";
    }

    /**
     * Cuántos pedidos del grupo tienen el estado indicado.
     *
     * @param  'pendiente'|'vendido'|'cancelado'  $estado
     * @return literal-string
     */
    private static function contarEstado(string $estado): string
    {
        return "SUM(CASE WHEN pe.estado = '{$estado}' THEN 1 ELSE 0 END)";
    }

    /**
     * Código visible del documento (V-00012), como en las pantallas; null si no hay número. LPAD
     * solo rellena: un número de 6 cifras o más se muestra completo.
     *
     * @param  literal-string  $serie
     * @param  literal-string  $columna
     * @return literal-string
     */
    public static function sqlCodigo(string $serie, string $columna): string
    {
        return "CONCAT('{$serie}-', IF({$columna} < 100000, LPAD({$columna}, 5, '0'), {$columna}))";
    }

    /**
     * @param  literal-string  $serie
     * @param  literal-string  $columna
     * @return literal-string
     */
    private function codigo(string $serie, string $columna): string
    {
        return self::sqlCodigo($serie, $columna);
    }

    private function conSucursal(Builder $consulta, string $columna): Builder
    {
        return $consulta->when($this->sucursalVendedor !== null, fn (Builder $q) => $q->where($columna, $this->sucursalVendedor));
    }

    /**
     * @param  literal-string  $nombre
     * @param  literal-string  $sql
     * @param  literal-string|null  $orden
     */
    private function campo(string $nombre, string $etiqueta, string $tipo, string $sql, bool $administrador = false, ?string $orden = null): Campo
    {
        return new Campo($nombre, $etiqueta, $tipo, $sql, soloAdministrador: $administrador, ordenSql: $orden);
    }
}
