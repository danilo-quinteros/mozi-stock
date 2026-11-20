<?php

namespace App\Domain\AgenteMozi\Datos;

use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\AgenteMozi\Herramientas\ArgumentoInvalido;
use App\Domain\Creditos\Consultas\CuentasPorCobrar;
use App\Domain\CuentasPagar\Consultas\CuentasPorPagar;
use App\Domain\Produccion\Consultas\MaterialesEnvasado;
use App\Models\Producto;
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
 *
 * Producción y lotes (§ 5.2): las fuentes de producción solo existen para el administrador de una empresa con la
 * capacidad produccion, y la de lotes para una empresa con Lotes y vencimientos. Las materias primas nunca aparecen en
 * productos ni en stock (son de venta): tienen su propia fuente. Lo producido repite los criterios de Reportes de
 * producción: órdenes finalizadas por su fecha de finalización.
 */
final class CatalogoDatos
{
    /** Fuentes generales (sin funciones por rubro). */
    public const FUENTES = [
        'productos', 'stock', 'ventas', 'productos_vendidos', 'pedidos', 'clientes', 'devoluciones', 'productos_devueltos',
        'compras', 'productos_comprados', 'proveedores', 'dinero', 'egresos', 'cuentas_por_cobrar', 'cuentas_por_pagar', 'movimientos_inventario',
    ];

    /** Fuentes del vendedor: su sucursal, sin costos, compras, gastos ni cuentas. */
    public const FUENTES_VENDEDOR = ['productos', 'stock', 'ventas', 'productos_vendidos', 'pedidos', 'clientes'];

    /** Fuentes de producción: administrador con la capacidad produccion (PR16). */
    public const FUENTES_PRODUCCION = ['materias_primas', 'recetas', 'ordenes_produccion', 'produccion', 'consumos_produccion', 'envasados', 'materiales_envasado'];

    /** Lotes con stock: empresa con Lotes y vencimientos; el vendedor ve su sucursal, sin costos ni materias primas. */
    public const FUENTE_LOTES = 'lotes';

    /** Todo el catálogo, para validar el nombre; qué fuentes puede usar cada usuario lo decide fuentesPermitidas(). */
    public const TODAS = [...self::FUENTES, ...self::FUENTES_PRODUCCION, self::FUENTE_LOTES];

    private const DIAS = "'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'";

    /** Nombre visible del producto, igual que en Inventario (requiere los alias p y u). */
    private const PRODUCTO = "CASE WHEN p.contenido IS NOT NULL THEN CONCAT(p.nombre, ' ', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM p.contenido)), ' ', u.abreviatura) ELSE p.nombre END";

    /** Producto de venta con receta vigente (fabricado). Usa el índice único (empresa_id, producto_vigente). */
    private const CON_RECETA = 'EXISTS (SELECT 1 FROM recetas rv WHERE rv.empresa_id = p.empresa_id AND rv.producto_vigente = p.id)';

    /** Origen del producto de venta: presentación (sale de envasar un granel), fabricado (tiene receta) o reventa. */
    private const ORIGEN = "CASE WHEN p.granel_id IS NOT NULL THEN 'presentación' WHEN ".self::CON_RECETA." THEN 'fabricado' ELSE 'reventa' END";

    /** Clase del producto (requiere el alias p). */
    private const CLASE = "CASE WHEN p.clase = 'materia_prima' THEN 'materia prima' ELSE 'venta' END";

    /** Número visible de la orden (OP-0015), como OrdenProduccion::formatearCodigo: 4 cifras, sin recortar las más largas. */
    private const ORDEN = "CONCAT('OP-', IF(o.numero < 10000, LPAD(o.numero, 4, '0'), o.numero))";

    private readonly int $empresaId;

    /** Sucursal fija sin vista amplia; null = vista amplia (toda la empresa). */
    private readonly ?int $sucursalVendedor;

    private readonly string $desfase;

    private readonly string $hoy;

    /** Permiso produccion.gestionar con la capacidad produccion. */
    private readonly bool $produccion;

    /** Empresa con Lotes y vencimientos. */
    private readonly bool $lotes;

    public function __construct(ContextoAgente $contexto)
    {
        $this->empresaId = $contexto->empresaId;
        // Sin vista amplia y sin sucursal no se ve ninguna fila: 0 nunca es un id válido.
        $this->sucursalVendedor = $contexto->amplio ? null : ($contexto->sucursalId ?? 0);
        $this->desfase = $contexto->fechaReferencia->format('P');
        $this->hoy = $contexto->fechaReferencia->format('Y-m-d');
        $this->produccion = $contexto->produccion();
        $this->lotes = $contexto->lotes();

        if (preg_match('/^[+-]\d{2}:\d{2}$/', $this->desfase) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->hoy) !== 1) {
            throw new LogicException('Fecha de referencia inválida para la consulta.');
        }
    }

    /**
     * Catálogo solo para describir fuentes y campos al modelo: sin empresa, nunca se ejecuta.
     *
     * @param  bool  $administrador  vista amplia (ContextoAgente::amplio)
     * @param  list<string>  $capacidades  funciones activas de la empresa del usuario
     * @param  bool|null  $produccion  permiso produccion.gestionar; null = el de la vista amplia
     */
    public static function paraDescribir(bool $administrador, array $capacidades = [], ?bool $produccion = null): self
    {
        $zona = (string) config('mozistock.business_timezone');

        return new self(new ContextoAgente(0, 0, $administrador, null, $zona, CarbonImmutable::now($zona), 'es', '', $capacidades, $produccion ?? $administrador));
    }

    public function administrador(): bool
    {
        return $this->sucursalVendedor === null;
    }

    public function produccion(): bool
    {
        return $this->produccion;
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
        return [
            ...($this->administrador() ? self::FUENTES : self::FUENTES_VENDEDOR),
            ...($this->produccion ? self::FUENTES_PRODUCCION : []),
            ...($this->lotes ? [self::FUENTE_LOTES] : []),
        ];
    }

    public function fuente(string $nombre): Fuente
    {
        if (! in_array($nombre, $this->fuentesPermitidas(), true)) {
            $motivo = match (true) {
                in_array($nombre, self::FUENTES_PRODUCCION, true) => 'solo existe para quien gestiona Producción en una empresa con Producción activa',
                $nombre === self::FUENTE_LOTES => 'solo existe en empresas con Lotes y vencimientos activo',
                default => 'no está disponible para el rol del usuario',
            };

            throw new ArgumentoInvalido("La fuente {$nombre} {$motivo}. Fuentes disponibles: ".implode(', ', $this->fuentesPermitidas()).'.');
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
            'materias_primas' => $this->materiasPrimas(),
            'recetas' => $this->recetas(),
            'ordenes_produccion' => $this->ordenesProduccion(),
            'produccion' => $this->produccionFinalizada(),
            'consumos_produccion' => $this->consumosProduccion(),
            'envasados' => $this->envasados(),
            'materiales_envasado' => $this->materialesEnvasado(),
            'lotes' => $this->lotesConStock(),
            default => throw new ArgumentoInvalido("La fuente {$nombre} no existe."),
        };
    }

    private function productos(): Fuente
    {
        // Sin control de stock (platos, servicios): stock vacío, no 0, y fuera de «sin stock».
        $existencia = 'CASE WHEN p.controla_stock = 1 THEN COALESCE(st.existencia, 0) END';

        return new Fuente('productos', $this->administrador()
            ? 'Catálogo con precios y stock actual (suma de las sucursales activas). Los productos sin control de stock (platos, servicios) tienen el stock vacío. Sin fecha principal.'
            : 'Catálogo con precios y stock actual de tu sucursal. Los productos sin control de stock (platos, servicios) tienen el stock vacío. Sin fecha principal.',
            false,
            fn () => DB::table('productos as p')
                ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
                ->leftJoin('categorias as cat', 'cat.id', '=', 'p.categoria_id')
                ->leftJoinSub($this->conSucursal(DB::table('stock_niveles as sn')
                    ->join('sucursales as s', fn (JoinClause $j) => $j->on('s.id', '=', 'sn.sucursal_id')->where('s.activa', true))
                    ->where('sn.empresa_id', $this->empresaId), 'sn.sucursal_id')
                    ->groupBy('sn.producto_id')
                    ->select(['sn.producto_id', DB::raw('SUM(sn.cantidad) AS existencia')]), 'st', 'st.producto_id', '=', 'p.id')
                ->where('p.empresa_id', $this->empresaId)
                // Las materias primas no se venden: tienen su propia fuente (materias_primas), solo con Producción.
                ->where('p.clase', Producto::CLASE_VENTA),
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
                $this->campo('control_stock', __('Control de stock'), 'string', "CASE WHEN p.controla_stock = 1 THEN 'controla stock' ELSE 'sin control de stock' END"),
                $this->campo('stock_minimo', __('Stock mínimo'), 'number', 'p.stock_minimo'),
                $this->campo('estado', __('Estado'), 'string', "CASE WHEN p.activo = 1 THEN 'activo' ELSE 'inactivo' END"),
                $this->campo('fecha_alta', __('Fecha de alta'), 'date', "DATE({$this->local('p.created_at')})"),
                ...$this->conProduccion([
                    $this->campo('origen', __('Origen'), 'string', self::ORIGEN, administrador: true),
                    // El costo solo se mantiene en lo que se fabrica o se envasa; en reventa queda vacío.
                    $this->campo('costo_materiales', __('Costo de materiales'), 'money', 'CASE WHEN p.granel_id IS NOT NULL OR '.self::CON_RECETA.' THEN p.costo_promedio END', administrador: true),
                ]),
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

        return new Fuente('stock', 'Existencia actual por producto y sucursal activa, con su mínimo y alerta (sin stock, bajo mínimo o normal). Solo productos que controlan stock. Sin fecha principal.',
            false,
            fn () => $this->conSucursal(DB::table('productos as p')
                ->join('sucursales as s', fn (JoinClause $j) => $j->on('s.empresa_id', '=', 'p.empresa_id')->where('s.activa', true))
                ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
                ->leftJoin('categorias as cat', 'cat.id', '=', 'p.categoria_id')
                ->leftJoin('stock_niveles as sn', fn (JoinClause $j) => $j->on('sn.empresa_id', '=', 'p.empresa_id')
                    ->on('sn.sucursal_id', '=', 's.id')->on('sn.producto_id', '=', 'p.id'))
                ->where('p.empresa_id', $this->empresaId)
                ->where('p.clase', Producto::CLASE_VENTA)
                ->where('p.controla_stock', true), 's.id'),
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
                ...$this->conProduccion([$this->campo('origen', __('Origen'), 'string', self::ORIGEN, administrador: true)]),
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
        return new Fuente('productos_comprados', 'Líneas de compras por producto con su costo unitario. clase: venta (mercadería) o materia prima (insumos de Producción).',
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
                $this->campo('clase', __('Clase'), 'string', self::CLASE),
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
                $this->campo('beneficiario', __('Proveedor'), 'string', 'pr.nombre'),
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
        return new Fuente('movimientos_inventario', 'Movimientos de stock por producto y sucursal: cantidad positiva entra y negativa sale. tipo: venta, compra, entrada, salida, ajuste, traspaso o devolucion'
            .($this->produccion ? '; con Producción también produccion (motivo consumo = materia prima descontada al aprobar, cierre = lo obtenido y el ajuste del consumo real al finalizar, anulacion = devolución al anular) y envasado' : '')
            .'. clase: venta o materia prima.',
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
                $this->campo('clase', __('Clase'), 'string', self::CLASE),
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
     * Materias primas por sucursal activa (§ 5.2), con el mínimo efectivo y el aviso de Producción › Materias primas
     * (ListadoMateriasPrimas). Utilizable = saldo − lotes vencidos, nunca negativo, como DisponibilidadMaterias: lo
     * vencido solo cuenta con Lotes y vencimientos activo y en materias primas que controlan vencimiento.
     */
    private function materiasPrimas(): Fuente
    {
        $existencia = 'COALESCE(sn.cantidad, 0)';
        $vencido = $this->lotes ? 'CASE WHEN p.controla_vencimiento = 1 THEN COALESCE(ve.cantidad, 0) ELSE 0 END' : '0';
        $utilizable = "GREATEST({$existencia} - {$vencido}, 0)";
        $minimo = 'COALESCE(sn.stock_minimo, p.stock_minimo)';
        $bajo = "{$minimo} > 0 AND {$existencia} <= {$minimo}";
        $sobre = "p.stock_maximo IS NOT NULL AND {$existencia} > p.stock_maximo";
        $tope = "COALESCE(p.stock_maximo, {$minimo})";
        $vencidos = DB::table('stock_lotes as sl')
            ->join('lotes as l', fn (JoinClause $j) => $j->on('l.id', '=', 'sl.lote_id')->on('l.empresa_id', '=', 'sl.empresa_id'))
            ->where('sl.empresa_id', $this->empresaId)
            ->where('sl.cantidad', '>', 0)
            ->where('l.vencimiento', '<', $this->hoy)
            ->groupBy('sl.sucursal_id', 'sl.producto_id')
            ->select(['sl.sucursal_id', 'sl.producto_id', DB::raw('SUM(sl.cantidad) AS cantidad')]);
        $usos = DB::table('receta_items as ri')
            ->join('recetas as r', fn (JoinClause $j) => $j->on('r.id', '=', 'ri.receta_id')->on('r.empresa_id', '=', 'ri.empresa_id'))
            ->join('productos as pr', fn (JoinClause $j) => $j->on('pr.id', '=', 'r.producto_id')->on('pr.empresa_id', '=', 'r.empresa_id'))
            ->join('unidades_medida as pu', 'pu.id', '=', 'pr.unidad_medida_id')
            ->where('ri.empresa_id', $this->empresaId)
            ->where('r.vigente', true)
            ->where('r.activa', true)
            ->groupBy('ri.producto_id')
            ->select(['ri.producto_id', DB::raw('COUNT(DISTINCT r.id) AS recetas'),
                DB::raw('GROUP_CONCAT(DISTINCT '.Producto::sqlNombreCompleto('pr', 'pu')." ORDER BY pr.nombre SEPARATOR ', ') AS productos")]);

        return new Fuente('materias_primas', 'Materias primas e insumos de Producción (no se venden) por sucursal activa: existencia, lo que está en lotes vencidos, utilizable (lo que se puede usar para producir), mínimo, máximo, alerta (sin stock, bajo mínimo, sobre máximo o normal) y cantidad sugerida para comprar (hasta el máximo o, si no tiene, hasta el mínimo, como Producción › Sugerencia de compra). usos = productos cuya receta vigente la usa. Sin fecha principal.',
            true,
            fn () => DB::table('productos as p')
                ->join('sucursales as s', fn (JoinClause $j) => $j->on('s.empresa_id', '=', 'p.empresa_id')->where('s.activa', true))
                ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
                ->leftJoin('stock_niveles as sn', fn (JoinClause $j) => $j->on('sn.empresa_id', '=', 'p.empresa_id')
                    ->on('sn.sucursal_id', '=', 's.id')->on('sn.producto_id', '=', 'p.id'))
                ->leftJoinSub($vencidos, 've', fn (JoinClause $j) => $j->on('ve.sucursal_id', '=', 's.id')->on('ve.producto_id', '=', 'p.id'))
                ->leftJoinSub($usos, 'uso', 'uso.producto_id', '=', 'p.id')
                ->where('p.empresa_id', $this->empresaId)
                ->where('p.clase', Producto::CLASE_MATERIA_PRIMA),
            [
                $this->campo('materia_prima', __('Materia prima'), 'string', Producto::sqlNombreCompleto('p', 'u')),
                $this->campo('codigo', __('Código'), 'string', 'p.sku'),
                $this->campo('unidad', __('Unidad'), 'string', 'u.nombre'),
                $this->campo('sucursal', __('Sucursal'), 'string', 's.nombre'),
                $this->campo('existencia', __('Existencia'), 'number', $existencia),
                $this->campo('vencido', __('En lotes vencidos'), 'number', $vencido),
                $this->campo('utilizable', __('Utilizable'), 'number', $utilizable),
                $this->campo('minimo', __('Mínimo'), 'number', $minimo),
                $this->campo('maximo', __('Máximo'), 'number', 'p.stock_maximo'),
                $this->campo('alerta', __('Alerta'), 'string', "CASE WHEN {$existencia} <= 0 THEN 'sin stock' WHEN {$bajo} THEN 'bajo mínimo' WHEN {$sobre} THEN 'sobre máximo' ELSE 'normal' END"),
                $this->campo('sugerido_comprar', __('Sugerido para comprar'), 'number', "CASE WHEN {$bajo} AND {$tope} - {$existencia} > 0 THEN {$tope} - {$existencia} END"),
                $this->campo('costo_promedio', __('Costo promedio'), 'money', 'p.costo_promedio'),
                $this->campo('valor_costo', __('Valor a costo'), 'money', "{$existencia} * p.costo_promedio"),
                $this->campo('controla_lote', __('Controla vencimiento'), 'string', "CASE WHEN p.controla_vencimiento = 1 THEN 'sí' ELSE 'no' END"),
                $this->campo('recetas', __('Recetas que la usan'), 'integer', 'COALESCE(uso.recetas, 0)'),
                $this->campo('usos', __('Se usa en'), 'string', 'uso.productos'),
                $this->campo('observacion', __('Observación'), 'string', 'LEFT(p.observacion, 200)'),
                $this->campo('estado', __('Estado'), 'string', "CASE WHEN p.activo = 1 THEN 'activa' ELSE 'inactiva' END"),
                Campo::medida('conteo', __('Registros'), 'integer', 'COUNT(*)'),
                Campo::medida('materias', __('Materias primas'), 'integer', 'COUNT(DISTINCT p.id)'),
                Campo::medida('existencia_total', __('Existencia total'), 'number', "SUM({$existencia})"),
                Campo::medida('utilizable_total', __('Utilizable'), 'number', "SUM({$utilizable})"),
                Campo::medida('valor_costo_total', __('Valor a costo'), 'money', "SUM({$existencia} * p.costo_promedio)"),
                Campo::medida('sin_stock', __('Sin stock'), 'integer', "SUM(CASE WHEN {$existencia} <= 0 THEN 1 ELSE 0 END)"),
                Campo::medida('bajo_minimo', __('Bajo mínimo'), 'integer', "SUM(CASE WHEN {$bajo} THEN 1 ELSE 0 END)"),
                Campo::medida('sobre_maximo', __('Sobre máximo'), 'integer', "SUM(CASE WHEN {$sobre} THEN 1 ELSE 0 END)"),
            ],
            ['materia_prima', 'sucursal', 'existencia', 'utilizable', 'minimo', 'alerta'],
            ['materia_prima', 'asc'],
            soloActivos: 'p.activo = 1',
        );
    }

    /**
     * Líneas de las recetas (§ 5.2): una fila por materia prima de cada versión. Por defecto solo las vigentes y
     * activas; con el filtro estado se ven las desactivadas y las versiones anteriores.
     */
    private function recetas(): Fuente
    {
        $costoLinea = 'ri.cantidad * m.costo_promedio';
        $estado = "CASE WHEN r.vigente = 0 THEN 'versión anterior' WHEN r.activa = 0 THEN 'desactivada' ELSE 'vigente' END";

        return new Fuente('recetas', 'Recetas de los productos fabricados: una fila por materia prima de cada versión, con la cantidad que lleva para producir la cantidad base. estado: vigente, desactivada o versión anterior (por defecto solo vigente). Costos con el costo promedio actual de cada materia prima: es costo de materiales, sin mano de obra ni gastos indirectos; costo_por_unidad = costo de la línea ÷ cantidad base. Fecha principal: creación de la versión.',
            true,
            fn () => DB::table('receta_items as ri')
                ->join('recetas as r', fn (JoinClause $j) => $j->on('r.id', '=', 'ri.receta_id')->on('r.empresa_id', '=', 'ri.empresa_id'))
                ->join('productos as p', fn (JoinClause $j) => $j->on('p.id', '=', 'r.producto_id')->on('p.empresa_id', '=', 'r.empresa_id'))
                ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
                ->join('productos as m', fn (JoinClause $j) => $j->on('m.id', '=', 'ri.producto_id')->on('m.empresa_id', '=', 'ri.empresa_id'))
                ->join('unidades_medida as mu', 'mu.id', '=', 'm.unidad_medida_id')
                ->leftJoin('users as us', 'us.id', '=', 'r.usuario_id')
                ->where('ri.empresa_id', $this->empresaId),
            [
                $this->campo('producto', __('Producto'), 'string', Producto::sqlNombreCompleto('p', 'u')),
                $this->campo('sku', __('SKU'), 'string', 'p.sku'),
                $this->campo('version', __('Versión'), 'integer', 'r.version'),
                $this->campo('estado', __('Estado'), 'string', $estado),
                $this->campo('cantidad_base', __('Cantidad base'), 'number', 'r.cantidad_base'),
                $this->campo('unidad_producto', __('Unidad del producto'), 'string', 'u.nombre'),
                $this->campo('materia_prima', __('Materia prima'), 'string', Producto::sqlNombreCompleto('m', 'mu')),
                $this->campo('codigo_materia', __('Código de la materia prima'), 'string', 'm.sku'),
                $this->campo('cantidad', __('Cantidad'), 'number', 'ri.cantidad'),
                $this->campo('unidad', __('Unidad'), 'string', 'mu.nombre'),
                $this->campo('estado_materia', __('Estado de la materia prima'), 'string', "CASE WHEN m.activo = 1 THEN 'activa' ELSE 'inactiva' END"),
                $this->campo('costo_promedio', __('Costo promedio'), 'money', 'm.costo_promedio'),
                $this->campo('costo_linea', __('Costo de la línea'), 'money', $costoLinea),
                $this->campo('costo_por_unidad', __('Costo por unidad producida'), 'money', "{$costoLinea} / NULLIF(r.cantidad_base, 0)"),
                $this->campo('nota', __('Nota'), 'string', 'LEFT(r.nota, 200)'),
                $this->campo('creada_por', __('Creada por'), 'string', 'us.name'),
                ...$this->fechas('r.created_at'),
                Campo::medida('conteo', __('Líneas'), 'integer', 'COUNT(*)'),
                Campo::medida('recetas', __('Recetas'), 'integer', 'COUNT(DISTINCT r.id)'),
                Campo::medida('productos', __('Productos'), 'integer', 'COUNT(DISTINCT r.producto_id)'),
                Campo::medida('materias', __('Materias primas'), 'integer', 'COUNT(DISTINCT ri.producto_id)'),
                Campo::medida('costo_receta', __('Costo de la receta'), 'money', "SUM({$costoLinea})"),
                Campo::medida('costo_unitario_receta', __('Costo por unidad producida'), 'money', "SUM({$costoLinea} / NULLIF(r.cantidad_base, 0))"),
            ],
            ['producto', 'version', 'cantidad_base', 'materia_prima', 'cantidad', 'unidad', 'costo_linea'],
            ['producto', 'asc'],
            'r.created_at',
            soloActivos: 'r.vigente = 1 AND r.activa = 1',
        );
    }

    /**
     * Órdenes de producción en todos sus estados (§ 5.2), para el control: atrasos, anulaciones, quién emitió. Lo
     * producido está en la fuente produccion (solo finalizadas), así el modelo no cuenta como hecho lo emitido.
     */
    private function ordenesProduccion(): Fuente
    {
        $abierta = "o.estado IN ('emitida', 'aprobada')";
        $atrasada = "{$abierta} AND o.fecha_programada < referencia.hoy";
        $finalizada = "o.estado = 'finalizada'";

        return new Fuente('ordenes_produccion', 'Órdenes de producción en todos sus estados: emitida (todavía no descontó materia prima ni la reserva), aprobada (ya descontó la materia prima), finalizada (ya ingresó lo obtenido) o anulada. dias_atraso = días desde la fecha programada de una emitida o aprobada. Para cuánto se produjo usa la fuente produccion. Fecha principal: emisión.',
            true,
            fn () => DB::table('ordenes_produccion as o')
                ->join('productos as p', fn (JoinClause $j) => $j->on('p.id', '=', 'o.producto_id')->on('p.empresa_id', '=', 'o.empresa_id'))
                ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
                ->join('sucursales as s', fn (JoinClause $j) => $j->on('s.id', '=', 'o.sucursal_id')->on('s.empresa_id', '=', 'o.empresa_id'))
                ->leftJoin('recetas as r', fn (JoinClause $j) => $j->on('r.id', '=', 'o.receta_id')->on('r.empresa_id', '=', 'o.empresa_id'))
                ->leftJoin('lotes as l', fn (JoinClause $j) => $j->on('l.id', '=', 'o.lote_id')->on('l.empresa_id', '=', 'o.empresa_id'))
                ->leftJoin('users as ue', 'ue.id', '=', 'o.emitida_por')
                ->leftJoin('users as uf', 'uf.id', '=', 'o.finalizada_por')
                ->where('o.empresa_id', $this->empresaId),
            [
                $this->campo('orden', __('Orden'), 'string', self::ORDEN, orden: 'o.numero'),
                ...$this->fechas('o.created_at'),
                $this->campo('estado', __('Estado'), 'string', 'o.estado'),
                $this->campo('producto', __('Producto'), 'string', Producto::sqlNombreCompleto('p', 'u')),
                $this->campo('sku', __('SKU'), 'string', 'p.sku'),
                $this->campo('unidad', __('Unidad'), 'string', 'u.nombre'),
                $this->campo('sucursal', __('Sucursal'), 'string', 's.nombre'),
                $this->campo('fecha_programada', __('Fecha programada'), 'date', 'o.fecha_programada'),
                $this->campo('fecha_aprobacion', __('Fecha de aprobación'), 'date', "DATE({$this->local('o.aprobada_en')})"),
                $this->campo('fecha_finalizacion', __('Fecha de finalización'), 'date', "DATE({$this->local('o.finalizada_en')})"),
                $this->campo('fecha_anulacion', __('Fecha de anulación'), 'date', "DATE({$this->local('o.anulada_en')})"),
                $this->campo('dias_atraso', __('Días de atraso'), 'integer', "CASE WHEN {$atrasada} THEN DATEDIFF(referencia.hoy, o.fecha_programada) ELSE 0 END"),
                $this->campo('planificado', __('Planificado'), 'number', 'o.cantidad_planificada'),
                $this->campo('obtenido', __('Obtenido'), 'number', "CASE WHEN {$finalizada} THEN o.cantidad_obtenida END"),
                $this->campo('rendimiento', __('Rendimiento'), 'percent', "CASE WHEN {$finalizada} THEN 100 * o.cantidad_obtenida / NULLIF(o.cantidad_planificada, 0) END"),
                $this->campo('costo_total', __('Costo de materiales'), 'money', "CASE WHEN {$finalizada} THEN o.costo_total END"),
                $this->campo('version_receta', __('Versión de receta'), 'integer', 'r.version'),
                $this->campo('emitida_por', __('Emitida por'), 'string', 'ue.name'),
                $this->campo('finalizada_por', __('Finalizada por'), 'string', 'uf.name'),
                $this->campo('nota', __('Nota'), 'string', 'LEFT(o.nota, 200)'),
                $this->campo('motivo_anulacion', __('Motivo de anulación'), 'string', 'LEFT(o.motivo_anulacion, 200)'),
                $this->campo('lote', __('Lote'), 'string', 'l.numero'),
                Campo::medida('conteo', __('Órdenes'), 'integer', 'COUNT(*)'),
                Campo::medida('emitidas', __('Emitidas'), 'integer', "SUM(CASE WHEN o.estado = 'emitida' THEN 1 ELSE 0 END)"),
                Campo::medida('aprobadas', __('Aprobadas'), 'integer', "SUM(CASE WHEN o.estado = 'aprobada' THEN 1 ELSE 0 END)"),
                Campo::medida('finalizadas', __('Finalizadas'), 'integer', "SUM(CASE WHEN {$finalizada} THEN 1 ELSE 0 END)"),
                Campo::medida('anuladas', __('Anuladas'), 'integer', "SUM(CASE WHEN o.estado = 'anulada' THEN 1 ELSE 0 END)"),
                Campo::medida('atrasadas', __('Atrasadas'), 'integer', "SUM(CASE WHEN {$atrasada} THEN 1 ELSE 0 END)"),
                Campo::medida('planificado_total', __('Planificado'), 'number', 'SUM(o.cantidad_planificada)'),
            ],
            ['orden', 'fecha', 'estado', 'producto', 'sucursal', 'fecha_programada', 'planificado'],
            ['fecha', 'desc'],
            'o.created_at',
        );
    }

    /**
     * Lo producido (§ 5.2): órdenes finalizadas por su fecha de finalización, los mismos criterios y sumas que
     * Producción › Reportes de producción (ReporteProduccion).
     */
    private function produccionFinalizada(): Fuente
    {
        return new Fuente('produccion', 'Lo producido: solo órdenes finalizadas, por su fecha de finalización (las mismas cifras de Reportes de producción). rendimiento = obtenido ÷ planificado × 100: menos de 100 es merma, más es excedente. costo = costo de materiales (materias primas a costo promedio al aprobar), sin mano de obra ni gastos indirectos. Las cantidades están en la unidad de cada producto: agrupa por producto antes de sumarlas. El lote de lo producido es el número de la orden.',
            true,
            fn () => DB::table('ordenes_produccion as o')
                ->join('productos as p', fn (JoinClause $j) => $j->on('p.id', '=', 'o.producto_id')->on('p.empresa_id', '=', 'o.empresa_id'))
                ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
                ->join('sucursales as s', fn (JoinClause $j) => $j->on('s.id', '=', 'o.sucursal_id')->on('s.empresa_id', '=', 'o.empresa_id'))
                ->leftJoin('lotes as l', fn (JoinClause $j) => $j->on('l.id', '=', 'o.lote_id')->on('l.empresa_id', '=', 'o.empresa_id'))
                ->leftJoin('users as uf', 'uf.id', '=', 'o.finalizada_por')
                ->where('o.empresa_id', $this->empresaId)
                ->where('o.estado', 'finalizada'),
            [
                $this->campo('orden', __('Orden'), 'string', self::ORDEN, orden: 'o.numero'),
                ...$this->fechas('o.finalizada_en'),
                $this->campo('producto', __('Producto'), 'string', Producto::sqlNombreCompleto('p', 'u')),
                $this->campo('sku', __('SKU'), 'string', 'p.sku'),
                $this->campo('unidad', __('Unidad'), 'string', 'u.nombre'),
                $this->campo('sucursal', __('Sucursal'), 'string', 's.nombre'),
                $this->campo('planificado', __('Planificado'), 'number', 'o.cantidad_planificada'),
                $this->campo('obtenido', __('Obtenido'), 'number', 'o.cantidad_obtenida'),
                $this->campo('diferencia', __('Diferencia'), 'number', 'o.cantidad_obtenida - o.cantidad_planificada'),
                $this->campo('rendimiento', __('Rendimiento'), 'percent', '100 * o.cantidad_obtenida / NULLIF(o.cantidad_planificada, 0)'),
                $this->campo('costo_total', __('Costo de materiales'), 'money', 'o.costo_total'),
                $this->campo('costo_unitario', __('Costo unitario'), 'money', 'o.costo_unitario'),
                $this->campo('lote', __('Lote'), 'string', 'l.numero'),
                $this->campo('finalizada_por', __('Finalizada por'), 'string', 'uf.name'),
                $this->campo('nota', __('Nota'), 'string', 'LEFT(o.nota, 200)'),
                Campo::medida('conteo', __('Órdenes'), 'integer', 'COUNT(*)'),
                Campo::medida('cantidad_planificada', __('Planificado'), 'number', 'SUM(o.cantidad_planificada)'),
                Campo::medida('cantidad_obtenida', __('Obtenido'), 'number', 'SUM(o.cantidad_obtenida)'),
                Campo::medida('rendimiento_total', __('Rendimiento'), 'percent', '100 * SUM(o.cantidad_obtenida) / NULLIF(SUM(o.cantidad_planificada), 0)'),
                Campo::medida('costo_produccion', __('Costo de materiales'), 'money', 'SUM(o.costo_total)'),
                Campo::medida('costo_por_unidad', __('Costo por unidad'), 'money', 'SUM(o.costo_total) / NULLIF(SUM(o.cantidad_obtenida), 0)'),
                Campo::medida('con_merma', __('Órdenes con merma'), 'integer', 'SUM(CASE WHEN o.cantidad_obtenida < o.cantidad_planificada THEN 1 ELSE 0 END)'),
                Campo::medida('productos', __('Productos'), 'integer', 'COUNT(DISTINCT o.producto_id)'),
            ],
            ['orden', 'fecha', 'producto', 'sucursal', 'planificado', 'obtenido', 'rendimiento', 'costo_total'],
            ['fecha', 'desc'],
            'o.finalizada_en',
        );
    }

    /**
     * Consumo real de materias primas de las órdenes finalizadas (§ 5.2): teórico (receta escalada), usado y su costo
     * con el costo guardado al aprobar, como la pestaña Materias primas de Reportes de producción.
     */
    private function consumosProduccion(): Fuente
    {
        $diferencia = '(i.cantidad_real - i.cantidad_teorica)';

        return new Fuente('consumos_produccion', 'Materias primas usadas por cada orden finalizada, por la fecha de finalización de la orden: teórico (lo de la receta escalada), usado (lo real), diferencia (positiva = se usó de más), desvío % y costo con el costo unitario guardado al aprobar. Las cantidades están en la unidad de cada materia prima: agrupa por materia prima antes de sumarlas.',
            true,
            fn () => DB::table('orden_produccion_items as i')
                ->join('ordenes_produccion as o', fn (JoinClause $j) => $j->on('o.id', '=', 'i.orden_id')->on('o.empresa_id', '=', 'i.empresa_id'))
                ->join('productos as p', fn (JoinClause $j) => $j->on('p.id', '=', 'o.producto_id')->on('p.empresa_id', '=', 'o.empresa_id'))
                ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
                ->join('productos as m', fn (JoinClause $j) => $j->on('m.id', '=', 'i.producto_id')->on('m.empresa_id', '=', 'i.empresa_id'))
                ->join('unidades_medida as mu', 'mu.id', '=', 'm.unidad_medida_id')
                ->join('sucursales as s', fn (JoinClause $j) => $j->on('s.id', '=', 'o.sucursal_id')->on('s.empresa_id', '=', 'o.empresa_id'))
                ->where('i.empresa_id', $this->empresaId)
                ->where('o.estado', 'finalizada'),
            [
                $this->campo('orden', __('Orden'), 'string', self::ORDEN, orden: 'o.numero'),
                ...$this->fechas('o.finalizada_en'),
                $this->campo('producto', __('Producto fabricado'), 'string', Producto::sqlNombreCompleto('p', 'u')),
                $this->campo('materia_prima', __('Materia prima'), 'string', Producto::sqlNombreCompleto('m', 'mu')),
                $this->campo('codigo_materia', __('Código de la materia prima'), 'string', 'm.sku'),
                $this->campo('unidad', __('Unidad'), 'string', 'mu.nombre'),
                $this->campo('sucursal', __('Sucursal'), 'string', 's.nombre'),
                $this->campo('teorico', __('Teórico'), 'number', 'i.cantidad_teorica'),
                $this->campo('usado', __('Usado'), 'number', 'i.cantidad_real'),
                $this->campo('diferencia', __('Diferencia'), 'number', $diferencia),
                $this->campo('desvio', __('Desvío'), 'percent', "100 * {$diferencia} / NULLIF(i.cantidad_teorica, 0)"),
                $this->campo('costo_unitario', __('Costo unitario'), 'money', 'i.costo_unitario'),
                $this->campo('costo_usado', __('Costo de lo usado'), 'money', 'i.cantidad_real * i.costo_unitario'),
                $this->campo('costo_diferencia', __('Costo de la diferencia'), 'money', "{$diferencia} * i.costo_unitario"),
                Campo::medida('conteo', __('Líneas'), 'integer', 'COUNT(*)'),
                Campo::medida('ordenes', __('Órdenes'), 'integer', 'COUNT(DISTINCT o.id)'),
                Campo::medida('teorico_total', __('Teórico'), 'number', 'SUM(i.cantidad_teorica)'),
                Campo::medida('usado_total', __('Usado'), 'number', 'SUM(i.cantidad_real)'),
                Campo::medida('diferencia_total', __('Diferencia'), 'number', "SUM({$diferencia})"),
                Campo::medida('desvio_total', __('Desvío'), 'percent', "100 * SUM({$diferencia}) / NULLIF(SUM(i.cantidad_teorica), 0)"),
                Campo::medida('costo_total', __('Costo de lo usado'), 'money', 'SUM(i.cantidad_real * i.costo_unitario)'),
                Campo::medida('costo_diferencia_total', __('Costo de la diferencia'), 'money', "SUM({$diferencia} * i.costo_unitario)"),
            ],
            ['orden', 'fecha', 'producto', 'materia_prima', 'teorico', 'usado', 'diferencia', 'costo_usado'],
            ['fecha', 'desc'],
            'o.finalizada_en',
        );
    }

    /**
     * Presentaciones obtenidas en cada envasado (§ 5.2). Usa el índice (empresa_id, created_at) de envasados.
     */
    private function envasados(): Fuente
    {
        return new Fuente('envasados', 'Envasados: una fila por presentación obtenida al envasar granel (las presentaciones llevan el mismo número de lote que el granel). unidades = unidades buenas; costo = granel y envases a costo promedio (costo de materiales). Fecha principal: fecha del envasado.',
            true,
            fn () => DB::table('envasado_items as ei')
                ->join('envasados as e', fn (JoinClause $j) => $j->on('e.id', '=', 'ei.envasado_id')->on('e.empresa_id', '=', 'ei.empresa_id'))
                ->join('productos as p', fn (JoinClause $j) => $j->on('p.id', '=', 'ei.producto_id')->on('p.empresa_id', '=', 'ei.empresa_id'))
                ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
                ->join('productos as g', fn (JoinClause $j) => $j->on('g.id', '=', 'e.granel_id')->on('g.empresa_id', '=', 'e.empresa_id'))
                ->join('unidades_medida as gu', 'gu.id', '=', 'g.unidad_medida_id')
                ->join('sucursales as s', fn (JoinClause $j) => $j->on('s.id', '=', 'e.sucursal_id')->on('s.empresa_id', '=', 'e.empresa_id'))
                ->leftJoin('lotes as l', fn (JoinClause $j) => $j->on('l.id', '=', 'e.lote_id')->on('l.empresa_id', '=', 'e.empresa_id'))
                ->leftJoin('users as us', 'us.id', '=', 'e.usuario_id')
                ->where('ei.empresa_id', $this->empresaId),
            [
                $this->campo('envasado', __('Envasado'), 'string', $this->codigo('ENV', 'e.numero'), orden: 'e.numero'),
                ...$this->fechas('e.created_at'),
                $this->campo('sucursal', __('Sucursal'), 'string', 's.nombre'),
                $this->campo('granel', __('Granel'), 'string', Producto::sqlNombreCompleto('g', 'gu')),
                $this->campo('lote', __('Lote'), 'string', 'l.numero'),
                $this->campo('presentacion', __('Presentación'), 'string', Producto::sqlNombreCompleto('p', 'u')),
                $this->campo('sku', __('SKU'), 'string', 'p.sku'),
                $this->campo('unidades', __('Unidades'), 'number', 'ei.unidades'),
                $this->campo('granel_por_unidad', __('Granel por unidad'), 'number', 'ei.granel_por_unidad'),
                $this->campo('granel_necesario', __('Granel necesario'), 'number', 'ei.unidades * ei.granel_por_unidad'),
                $this->campo('costo_unitario', __('Costo unitario'), 'money', 'ei.costo_unitario'),
                $this->campo('costo_total', __('Costo de materiales'), 'money', 'ei.costo_total'),
                $this->campo('usuario', __('Usuario'), 'string', 'us.name'),
                $this->campo('nota', __('Nota'), 'string', 'LEFT(e.nota, 200)'),
                Campo::medida('conteo', __('Líneas'), 'integer', 'COUNT(*)'),
                Campo::medida('envasados', __('Envasados'), 'integer', 'COUNT(DISTINCT e.id)'),
                Campo::medida('unidades_total', __('Unidades'), 'number', 'SUM(ei.unidades)'),
                Campo::medida('costo_total_envasado', __('Costo de materiales'), 'money', 'SUM(ei.costo_total)'),
                Campo::medida('costo_unitario_promedio', __('Costo unitario promedio'), 'money', 'SUM(ei.costo_total) / NULLIF(SUM(ei.unidades), 0)'),
                Campo::medida('presentaciones', __('Presentaciones'), 'integer', 'COUNT(DISTINCT ei.producto_id)'),
            ],
            ['envasado', 'fecha', 'sucursal', 'granel', 'lote', 'presentacion', 'unidades', 'costo_total'],
            ['fecha', 'desc'],
            'e.created_at',
        );
    }

    /**
     * Materiales de cada envasado (§ 5.2): el granel y cada envase, con lo necesario, lo usado y la merma
     * (MaterialesEnvasado). El período filtra por la fecha del envasado.
     */
    private function materialesEnvasado(): Fuente
    {
        $merma = '(mx.usado - mx.necesario)';

        return new Fuente('materiales_envasado', 'Materiales de cada envasado: el granel y cada envase (botellas, tapas, etiquetas). necesario = lo que piden las unidades obtenidas; usado = lo real; merma = usado − necesario (positiva = se perdió material). Costo con el costo guardado en el envasado. Las cantidades están en la unidad de cada material: agrupa por material antes de sumarlas. Fecha principal: fecha del envasado.',
            true,
            fn () => DB::query()->fromSub(MaterialesEnvasado::consulta($this->empresaId), 'mx')
                ->join('envasados as e', fn (JoinClause $j) => $j->on('e.id', '=', 'mx.envasado_id')->where('e.empresa_id', '=', $this->empresaId))
                ->join('productos as m', fn (JoinClause $j) => $j->on('m.id', '=', 'mx.producto_id')->where('m.empresa_id', '=', $this->empresaId))
                ->join('unidades_medida as mu', 'mu.id', '=', 'm.unidad_medida_id')
                ->join('productos as g', fn (JoinClause $j) => $j->on('g.id', '=', 'e.granel_id')->on('g.empresa_id', '=', 'e.empresa_id'))
                ->join('unidades_medida as gu', 'gu.id', '=', 'g.unidad_medida_id')
                ->join('sucursales as s', fn (JoinClause $j) => $j->on('s.id', '=', 'e.sucursal_id')->on('s.empresa_id', '=', 'e.empresa_id'))
                ->leftJoin('lotes as l', fn (JoinClause $j) => $j->on('l.id', '=', 'e.lote_id')->on('l.empresa_id', '=', 'e.empresa_id')),
            [
                $this->campo('envasado', __('Envasado'), 'string', $this->codigo('ENV', 'e.numero'), orden: 'e.numero'),
                ...$this->fechas('e.created_at'),
                $this->campo('sucursal', __('Sucursal'), 'string', 's.nombre'),
                $this->campo('granel', __('Granel envasado'), 'string', Producto::sqlNombreCompleto('g', 'gu')),
                $this->campo('lote', __('Lote'), 'string', 'l.numero'),
                $this->campo('material', __('Material'), 'string', Producto::sqlNombreCompleto('m', 'mu')),
                $this->campo('tipo', __('Tipo'), 'string', 'mx.tipo'),
                $this->campo('unidad', __('Unidad'), 'string', 'mu.nombre'),
                $this->campo('necesario', __('Necesario'), 'number', 'mx.necesario'),
                $this->campo('usado', __('Usado'), 'number', 'mx.usado'),
                $this->campo('merma', __('Merma'), 'number', $merma),
                $this->campo('merma_pct', __('Merma %'), 'percent', "100 * {$merma} / NULLIF(mx.necesario, 0)"),
                $this->campo('costo_unitario', __('Costo unitario'), 'money', 'mx.costo_unitario'),
                $this->campo('costo_usado', __('Costo de lo usado'), 'money', 'mx.usado * mx.costo_unitario'),
                $this->campo('costo_merma', __('Costo de la merma'), 'money', "{$merma} * mx.costo_unitario"),
                Campo::medida('conteo', __('Líneas'), 'integer', 'COUNT(*)'),
                Campo::medida('envasados', __('Envasados'), 'integer', 'COUNT(DISTINCT mx.envasado_id)'),
                Campo::medida('necesario_total', __('Necesario'), 'number', 'SUM(mx.necesario)'),
                Campo::medida('usado_total', __('Usado'), 'number', 'SUM(mx.usado)'),
                Campo::medida('merma_total', __('Merma'), 'number', "SUM({$merma})"),
                Campo::medida('merma_pct_total', __('Merma %'), 'percent', "100 * SUM({$merma}) / NULLIF(SUM(mx.necesario), 0)"),
                Campo::medida('costo_merma_total', __('Costo de la merma'), 'money', "SUM({$merma} * mx.costo_unitario)"),
            ],
            ['envasado', 'fecha', 'material', 'tipo', 'necesario', 'usado', 'merma', 'costo_merma'],
            ['fecha', 'desc'],
            'e.created_at',
        );
    }

    /**
     * Lotes con stock por sucursal (§ 5.2), como Inventario › Vencimientos (ReporteVencimientos): solo productos que
     * controlan vencimiento. El vendedor ve su sucursal, sin costos ni materias primas. Fecha principal: vencimiento.
     */
    private function lotesConStock(): Fuente
    {
        $vencido = 'l.vencimiento < referencia.hoy';
        $dias = 'DATEDIFF(l.vencimiento, referencia.hoy)';

        return new Fuente('lotes', 'Lotes con stock por sucursal (Inventario › Vencimientos), solo productos que controlan vencimiento. estado: vencido, vence en 30 días, vence en 60 días, vence en 90 días, vigente o sin fecha (stock de regularización, sin número ni vencimiento). dias_para_vencer negativo = días vencido. El día del vencimiento todavía se puede vender. Fecha principal: vencimiento (date_from y date_to filtran por vencimiento).'
            .($this->administrador() ? ' clase: venta o materia prima.' : ''),
            false,
            fn () => $this->conSucursal(DB::table('stock_lotes as sl')
                ->join('lotes as l', fn (JoinClause $j) => $j->on('l.id', '=', 'sl.lote_id')->on('l.empresa_id', '=', 'sl.empresa_id'))
                ->join('productos as p', fn (JoinClause $j) => $j->on('p.id', '=', 'sl.producto_id')->on('p.empresa_id', '=', 'sl.empresa_id'))
                ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
                ->join('sucursales as s', fn (JoinClause $j) => $j->on('s.id', '=', 'sl.sucursal_id')->on('s.empresa_id', '=', 'sl.empresa_id'))
                ->where('sl.empresa_id', $this->empresaId)
                ->where('p.controla_vencimiento', true)
                ->where('sl.cantidad', '>', 0)
                ->when(! $this->administrador(), fn (Builder $q) => $q->where('p.clase', Producto::CLASE_VENTA)), 'sl.sucursal_id'),
            [
                $this->campo('producto', __('Producto'), 'string', Producto::sqlNombreCompleto('p', 'u')),
                $this->campo('sku', __('SKU'), 'string', 'p.sku'),
                $this->campo('clase', __('Clase'), 'string', "CASE WHEN p.clase = 'materia_prima' THEN 'materia prima' ELSE 'venta' END", administrador: true),
                $this->campo('lote', __('Lote'), 'string', "COALESCE(l.numero, 'sin número (regularización)')"),
                $this->campo('vencimiento', __('Vencimiento'), 'date', 'l.vencimiento'),
                $this->campo('dias_para_vencer', __('Días para vencer'), 'integer', $dias),
                $this->campo('estado', __('Estado'), 'string', "CASE WHEN l.vencimiento IS NULL THEN 'sin fecha' WHEN {$vencido} THEN 'vencido'
                    WHEN {$dias} <= 30 THEN 'vence en 30 días' WHEN {$dias} <= 60 THEN 'vence en 60 días' WHEN {$dias} <= 90 THEN 'vence en 90 días' ELSE 'vigente' END"),
                $this->campo('sucursal', __('Sucursal'), 'string', 's.nombre'),
                $this->campo('cantidad', __('Cantidad'), 'number', 'sl.cantidad'),
                $this->campo('unidad', __('Unidad'), 'string', 'u.nombre'),
                $this->campo('costo_promedio', __('Costo promedio'), 'money', 'p.costo_promedio', administrador: true),
                $this->campo('valor_costo', __('Valor a costo'), 'money', 'sl.cantidad * p.costo_promedio', administrador: true),
                ...$this->fechas('l.vencimiento', false),
                Campo::medida('conteo', __('Registros'), 'integer', 'COUNT(*)'),
                Campo::medida('lotes', __('Lotes'), 'integer', 'COUNT(DISTINCT l.id)'),
                Campo::medida('productos', __('Productos'), 'integer', 'COUNT(DISTINCT p.id)'),
                Campo::medida('cantidad_total', __('Cantidad'), 'number', 'SUM(sl.cantidad)'),
                Campo::medida('vencidos', __('Vencidos'), 'integer', "SUM(CASE WHEN {$vencido} THEN 1 ELSE 0 END)"),
                Campo::medida('por_vencer_30', __('Vencen en 30 días'), 'integer', "SUM(CASE WHEN {$dias} BETWEEN 0 AND 30 THEN 1 ELSE 0 END)"),
                Campo::medida('valor_costo_total', __('Valor a costo'), 'money', 'SUM(sl.cantidad * p.costo_promedio)', true),
                Campo::medida('valor_vencido', __('Valor vencido a costo'), 'money', "SUM(CASE WHEN {$vencido} THEN sl.cantidad * p.costo_promedio ELSE 0 END)", true),
            ],
            ['producto', 'lote', 'vencimiento', 'estado', 'sucursal', 'cantidad'],
            ['vencimiento', 'asc'],
            'l.vencimiento',
            false,
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

    /**
     * Campos que solo existen con Producción (administrador con la capacidad).
     *
     * @param  list<Campo>  $campos
     * @return list<Campo>
     */
    private function conProduccion(array $campos): array
    {
        return $this->produccion ? $campos : [];
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
