<?php

use App\Http\Controllers\AgenteMoziController;
use App\Http\Controllers\CatalogoExternoFotoController;
use App\Http\Controllers\ComprobanteCuentaController;
use App\Http\Controllers\ComprobantePagoController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExportarDespachosController;
use App\Http\Controllers\ExportarMovimientosController;
use App\Http\Controllers\ExportarPedidosController;
use App\Http\Controllers\ExportarProductosController;
use App\Http\Controllers\ExportarReporteProduccionController;
use App\Http\Controllers\ExportarSugerenciaCompraController;
use App\Http\Controllers\ExportarUsuariosController;
use App\Http\Controllers\ExportarVencimientosController;
use App\Http\Controllers\FacturaSiatController;
use App\Http\Controllers\IdiomaController;
use App\Http\Controllers\ImpersonacionController;
use App\Http\Controllers\ImprimirController;
use App\Http\Controllers\ModeloImagenController;
use App\Http\Controllers\OperacionesCsvController;
use App\Http\Controllers\PedidoImprimirController;
use App\Http\Controllers\ProductoImagenController;
use App\Http\Controllers\QrCobroController;
use App\Http\Controllers\ReportesCsvController;
use App\Http\Controllers\SitioWebController;
use App\Http\Controllers\SucursalActivaController;
use App\Http\Controllers\SuscripcionesCsvController;
use App\Livewire\Admin\AgenteMozi as AdminAgenteMozi;
use App\Livewire\Admin\AjustesGlobales;
use App\Livewire\Admin\CatalogosSiat as AdminCatalogosSiat;
use App\Livewire\Admin\Comprobantes;
use App\Livewire\Admin\Empresas;
use App\Livewire\Admin\MigracionCyclone;
use App\Livewire\Admin\Panel;
use App\Livewire\Admin\SitioWeb as AdminSitioWeb;
use App\Livewire\Admin\Suscripciones;
use App\Livewire\AgenteMozi\Panel as AgenteMoziPanel;
use App\Livewire\Caja\Arqueo;
use App\Livewire\Catalogo\AgruparModelo;
use App\Livewire\Catalogo\ImportarProductos;
use App\Livewire\Catalogo\ModeloDetalle;
use App\Livewire\Catalogo\ModeloFormulario;
use App\Livewire\Catalogo\ProductoFormulario;
use App\Livewire\Catalogo\Productos;
use App\Livewire\Catalogo\TallasColores;
use App\Livewire\Compras\Detalle as CompraDetalle;
use App\Livewire\Compras\Lista as CompraLista;
use App\Livewire\Compras\NuevaCompra;
use App\Livewire\Contactos\ClienteFicha;
use App\Livewire\Contactos\Clientes;
use App\Livewire\Contactos\Proveedores;
use App\Livewire\Contactos\ProveedorFicha;
use App\Livewire\Creditos\Cobrar as CreditoCobrar;
use App\Livewire\Creditos\Cuenta as CreditoCuenta;
use App\Livewire\Creditos\Historial as CreditoHistorial;
use App\Livewire\Creditos\Lista as CreditoLista;
use App\Livewire\CuentasPagar\Detalle as CuentaPagarDetalle;
use App\Livewire\CuentasPagar\Lista as CuentaPagarLista;
use App\Livewire\Egresos\Panel as EgresosPanel;
use App\Livewire\Empresa\Configuracion;
use App\Livewire\Empresa\MiSuscripcion;
use App\Livewire\Empresa\RolFormulario;
use App\Livewire\Empresa\Sucursales;
use App\Livewire\Empresa\Usuarios;
use App\Livewire\Facturacion\Panel as FacturacionPanel;
use App\Livewire\Inventario\Movimientos;
use App\Livewire\Inventario\NuevoAjuste;
use App\Livewire\Inventario\NuevoTraspaso;
use App\Livewire\Inventario\TrazabilidadLote;
use App\Livewire\Inventario\Vencimientos;
use App\Livewire\Pedidos\Editor as PedidoEditor;
use App\Livewire\Pedidos\Lista as PedidoLista;
use App\Livewire\Produccion\EnvasadoDetalle;
use App\Livewire\Produccion\EnvasadoFormulario;
use App\Livewire\Produccion\Envasados;
use App\Livewire\Produccion\MateriaPrimaFormulario;
use App\Livewire\Produccion\MateriasPrimas;
use App\Livewire\Produccion\OrdenDetalle;
use App\Livewire\Produccion\Ordenes;
use App\Livewire\Produccion\OrdenFinalizar;
use App\Livewire\Produccion\OrdenFormulario;
use App\Livewire\Produccion\RecetaDetalle;
use App\Livewire\Produccion\RecetaFormulario;
use App\Livewire\Produccion\Recetas;
use App\Livewire\Produccion\Reportes as ReportesProduccion;
use App\Livewire\Reportes\Panel as ReportesPanel;
use App\Livewire\Ventas\Detalle as VentaDetalle;
use App\Livewire\Ventas\NuevaDevolucion;
use App\Livewire\Ventas\Pos;
use Illuminate\Support\Facades\Route;

// Raíz: sitio web público en modo SaaS; en standalone, redirige al panel o al acceso.
Route::get('/', [SitioWebController::class, 'inicio'])->name('home');

require __DIR__.'/sitio.php';

// Idioma de la interfaz: disponible también en la pantalla de acceso.
Route::post('idioma', IdiomaController::class)->middleware('throttle:30,1')->name('idioma');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // «Entrar como» otro usuario (soporte). La autorización vive en el Gate «impersonar».
    Route::post('impersonar/salir', [ImpersonacionController::class, 'salir'])->name('impersonar.salir');
    Route::post('impersonar/{usuario}', [ImpersonacionController::class, 'iniciar'])->whereNumber('usuario')->name('impersonar');
    Route::get('catalogo-externo/productos/{productoExterno}/foto', CatalogoExternoFotoController::class)->name('catalogo-externo.foto');

    // Panel de empresa: el contexto tenant sale del usuario autenticado, nunca de la URL. Las rutas con
    // permiso:<clave> responden 403 sin el permiso (ESPECIFICACION_PERMISOS.md § 6.4); la capacidad va antes
    // (404). Las pantallas y acciones vuelven a autorizar con sus policies.
    Route::middleware('empresa')->group(function () {
        Route::livewire('sucursales', Sucursales::class)->name('sucursales.index');
        // Las cajas se gestionan dentro de Sucursales y cajas; el enlace antiguo solo redirige.
        Route::redirect('sucursales/{sucursal}/cajas', '/sucursales')->whereNumber('sucursal')->name('sucursales.cajas');
        Route::livewire('usuarios', Usuarios::class)->name('usuarios.index');
        Route::get('usuarios/exportar', ExportarUsuariosController::class)->middleware('throttle:exportaciones')->name('usuarios.exportar');
        // Roles de la empresa (ESPECIFICACION_PERMISOS.md § 8): solo el administrador (RolPolicy); otra empresa → 404.
        Route::livewire('usuarios/roles/nuevo', RolFormulario::class)->name('usuarios.roles.crear');
        Route::livewire('usuarios/roles/{rol}', RolFormulario::class)->whereNumber('rol')->name('usuarios.roles.editar');
        Route::livewire('configuracion', Configuracion::class)->name('configuracion');
        // Tallas y colores (V1): sin la capacidad «variantes» responden 404, como si el módulo no existiera.
        Route::livewire('configuracion/tallas-y-colores', TallasColores::class)->middleware('capacidad:variantes')->name('configuracion.tallas-colores');
        // Facturación SIAT: siempre la empresa del usuario; el Gate exige que MoziStudios la haya habilitado.
        Route::livewire('facturacion', FacturacionPanel::class)->name('facturacion');
        Route::post('sucursal-activa', SucursalActivaController::class)->name('sucursal-activa');

        Route::livewire('productos', Productos::class)->name('productos.index');
        Route::get('productos/exportar', ExportarProductosController::class)->middleware('throttle:exportaciones')->name('productos.exportar');
        Route::livewire('productos/nuevo', ProductoFormulario::class)->middleware('permiso:productos.gestionar')->name('productos.crear');
        Route::middleware('capacidad:variantes')->group(function (): void {
            Route::livewire('productos/modelos/nuevo', ModeloFormulario::class)->middleware('permiso:productos.gestionar')->name('productos.modelos.crear');
            // Tallas y colores V3 (§ 9): agrupar productos sueltos marcados en el catálogo (?productos=1,2,3).
            Route::livewire('productos/modelos/agrupar', AgruparModelo::class)->middleware('permiso:productos.gestionar')->name('productos.modelos.agrupar');
            Route::livewire('productos/modelos/{modelo}', ModeloDetalle::class)->whereNumber('modelo')->name('productos.modelos.ver');
            Route::livewire('productos/modelos/{modelo}/editar', ModeloFormulario::class)->whereNumber('modelo')->middleware('permiso:productos.gestionar')->name('productos.modelos.editar');
            Route::get('productos/modelos/{modelo}/imagen', ModeloImagenController::class)->whereNumber('modelo')->name('productos.modelos.imagen');
        });
        Route::livewire('productos/importar', ImportarProductos::class)->middleware('permiso:productos.gestionar')->name('productos.importar');
        Route::livewire('productos/{producto}/editar', ProductoFormulario::class)->middleware('permiso:productos.gestionar')->name('productos.editar');
        Route::get('productos/{producto}/imagen', ProductoImagenController::class)->name('productos.imagen');

        Route::livewire('inventario', Movimientos::class)->middleware('permiso:inventario.historial')->name('inventario.movimientos');
        Route::get('inventario/exportar', ExportarMovimientosController::class)->middleware('throttle:exportaciones')->middleware('permiso:inventario.historial')->name('inventario.exportar');
        Route::livewire('inventario/ajuste', NuevoAjuste::class)->middleware('permiso:inventario.ajustar')->name('inventario.ajuste');
        Route::livewire('inventario/traspaso', NuevoTraspaso::class)->middleware('permiso:inventario.traspasar')->name('inventario.traspaso');
        // Lotes y vencimientos (L4): sin la capacidad responden 404, como si el módulo no existiera.
        Route::livewire('inventario/vencimientos', Vencimientos::class)->middleware('capacidad:lotes_vencimientos')->middleware('permiso:inventario.vencimientos')->name('inventario.vencimientos');
        Route::get('inventario/vencimientos/exportar', ExportarVencimientosController::class)->middleware(['capacidad:lotes_vencimientos', 'throttle:exportaciones'])->middleware('permiso:inventario.vencimientos')->name('inventario.vencimientos.exportar');
        Route::livewire('inventario/lotes/{lote}', TrazabilidadLote::class)->whereNumber('lote')->middleware('capacidad:lotes_vencimientos')->middleware('permiso:inventario.vencimientos')->name('inventario.lote');

        // Producción (ESPECIFICACION_PRODUCCION.md): sin la capacidad responden 404; sin el permiso
        // produccion.gestionar, 403. Cada pantalla exige además el gate usar-produccion.
        Route::middleware(['capacidad:produccion', 'permiso:produccion.gestionar'])->prefix('produccion')->name('produccion.')->group(function (): void {
            Route::livewire('materias-primas', MateriasPrimas::class)->name('materias');
            Route::livewire('materias-primas/nueva', MateriaPrimaFormulario::class)->name('materias.crear');
            Route::livewire('materias-primas/{producto}/editar', MateriaPrimaFormulario::class)->whereNumber('producto')->name('materias.editar');
            Route::get('materias-primas/sugerencia/exportar', ExportarSugerenciaCompraController::class)->middleware('throttle:exportaciones')->name('sugerencia.exportar');
            Route::livewire('recetas', Recetas::class)->name('recetas');
            Route::livewire('recetas/nueva', RecetaFormulario::class)->name('recetas.crear');
            Route::livewire('recetas/{producto}', RecetaDetalle::class)->whereNumber('producto')->name('recetas.ver');
            Route::livewire('recetas/{producto}/editar', RecetaFormulario::class)->whereNumber('producto')->name('recetas.editar');
            Route::livewire('ordenes', Ordenes::class)->name('ordenes');
            Route::livewire('ordenes/nueva', OrdenFormulario::class)->name('ordenes.crear');
            Route::livewire('ordenes/{orden}', OrdenDetalle::class)->whereNumber('orden')->name('ordenes.ver');
            Route::livewire('ordenes/{orden}/editar', OrdenFormulario::class)->whereNumber('orden')->name('ordenes.editar');
            Route::livewire('ordenes/{orden}/finalizar', OrdenFinalizar::class)->whereNumber('orden')->name('ordenes.finalizar');
            Route::livewire('envasados', Envasados::class)->name('envasados');
            Route::livewire('envasados/nuevo', EnvasadoFormulario::class)->name('envasados.crear');
            Route::livewire('envasados/{envasado}', EnvasadoDetalle::class)->whereNumber('envasado')->name('envasados.ver');
            Route::livewire('reportes', ReportesProduccion::class)->name('reportes');
            Route::get('reportes/exportar', ExportarReporteProduccionController::class)->middleware('throttle:exportaciones')->name('reportes.exportar');
        });

        // Punto de venta: guarda y confirma en un paso con las mismas acciones de Pedidos y ventas.
        Route::livewire('pos', Pos::class)->middleware('permiso:ventas.vender')->name('pos');
        Route::livewire('pedidos', PedidoLista::class)->middleware('permiso:ventas.ver')->name('pedidos.index');
        Route::get('pedidos/exportar', ExportarPedidosController::class)->middleware('throttle:exportaciones')->middleware('permiso:ventas.ver')->name('pedidos.exportar');
        Route::get('pedidos/despachos/exportar', ExportarDespachosController::class)->middleware(['capacidad:despachos', 'throttle:exportaciones'])->name('pedidos.despachos.exportar');
        Route::livewire('pedidos/nuevo', PedidoEditor::class)->middleware('permiso:ventas.vender')->name('pedidos.crear');
        Route::livewire('pedidos/{pedido}', PedidoEditor::class)->whereNumber('pedido')->name('pedidos.editar');
        Route::get('pedidos/{pedido}/imprimir', PedidoImprimirController::class)->whereNumber('pedido')->name('pedidos.imprimir');
        Route::livewire('ventas/{venta}', VentaDetalle::class)->whereNumber('venta')->name('ventas.ver');
        Route::livewire('ventas/{venta}/devolucion', NuevaDevolucion::class)->whereNumber('venta')->middleware('permiso:ventas.devolver')->name('ventas.devolucion');
        // Solo facturas validadas por el SIN; EmpresaScope en el binding y VentaPolicy en el controlador.
        Route::get('facturas/{factura}/imprimir', [FacturaSiatController::class, 'imprimir'])->whereNumber('factura')->name('facturas.imprimir');
        Route::get('facturas/{factura}/xml', [FacturaSiatController::class, 'xml'])->whereNumber('factura')->name('facturas.xml');

        Route::livewire('creditos', CreditoLista::class)->middleware('permiso:creditos.ver')->name('creditos.index');
        Route::get('creditos/exportar', [ReportesCsvController::class, 'creditos'])->middleware('throttle:exportaciones')->middleware('permiso:creditos.ver')->name('creditos.exportar');
        Route::livewire('creditos/{venta}', CreditoHistorial::class)->whereNumber('venta')->name('creditos.historial');
        Route::get('creditos/{venta}/exportar', [ReportesCsvController::class, 'historial'])->whereNumber('venta')->middleware('throttle:exportaciones')->name('creditos.historial.exportar');
        Route::get('creditos/{venta}/imprimir', [ImprimirController::class, 'credito'])->whereNumber('venta')->name('creditos.imprimir');
        // Cuentas por cobrar: cobro a un cliente, cuentas manuales y comprobantes privados.
        Route::livewire('creditos/cobrar', CreditoCobrar::class)->middleware('permiso:creditos.cobrar')->name('creditos.cobrar');
        Route::livewire('creditos/cuentas/{cuenta}', CreditoCuenta::class)->whereNumber('cuenta')->name('creditos.cuenta');
        Route::get('cobros/{cobro}/comprobante', [ComprobanteCuentaController::class, 'cobro'])->whereNumber('cobro')->name('cobros.comprobante');

        // Cuentas por pagar (permiso cuentas_pagar.gestionar; CuentaPagarPolicy).
        Route::livewire('cuentas-por-pagar', CuentaPagarLista::class)->middleware('permiso:cuentas_pagar.gestionar')->name('cuentas-pagar.index');
        // Enlace antiguo: «Nueva obligación» ahora es un modal de la lista.
        Route::get('cuentas-por-pagar/nueva', fn () => redirect()->route('cuentas-pagar.index', ['nueva' => 1]))->middleware('permiso:cuentas_pagar.gestionar')->name('cuentas-pagar.crear');
        Route::get('cuentas-por-pagar/exportar', [ReportesCsvController::class, 'cuentasPagar'])->middleware('throttle:exportaciones')->middleware('permiso:cuentas_pagar.gestionar')->name('cuentas-pagar.exportar');
        Route::livewire('cuentas-por-pagar/{cuenta}', CuentaPagarDetalle::class)->whereNumber('cuenta')->middleware('permiso:cuentas_pagar.gestionar')->name('cuentas-pagar.ver');
        Route::get('cuentas-por-pagar/{cuenta}/respaldo', [ComprobanteCuentaController::class, 'respaldo'])->whereNumber('cuenta')->middleware('permiso:cuentas_pagar.gestionar')->name('cuentas-pagar.respaldo');
        Route::get('cuentas-por-pagar/pagos/{pago}/comprobante', [ComprobanteCuentaController::class, 'pago'])->whereNumber('pago')->middleware('permiso:cuentas_pagar.gestionar')->name('cuentas-pagar.comprobante');

        Route::livewire('compras', CompraLista::class)->middleware('permiso:compras.gestionar')->name('compras.index');
        Route::get('compras/exportar', [OperacionesCsvController::class, 'compras'])->middleware('throttle:exportaciones')->middleware('permiso:compras.gestionar')->name('compras.exportar');
        Route::livewire('compras/nueva', NuevaCompra::class)->middleware('permiso:compras.gestionar')->name('compras.crear');
        Route::livewire('compras/{compra}', CompraDetalle::class)->whereNumber('compra')->middleware('permiso:compras.gestionar')->name('compras.ver');
        Route::get('compras/{compra}/imprimir', [ImprimirController::class, 'compra'])->whereNumber('compra')->middleware('permiso:compras.gestionar')->name('compras.imprimir');

        Route::livewire('egresos', EgresosPanel::class)->middleware('permiso:egresos.gestionar')->name('egresos.index');
        Route::get('egresos/exportar', [OperacionesCsvController::class, 'egresos'])->middleware('throttle:exportaciones')->middleware('permiso:egresos.gestionar')->name('egresos.exportar');

        Route::livewire('clientes', Clientes::class)->name('clientes.index');
        Route::get('clientes/exportar', [OperacionesCsvController::class, 'clientes'])->middleware('throttle:exportaciones')->name('clientes.exportar');
        Route::livewire('clientes/{cliente}', ClienteFicha::class)->whereNumber('cliente')->name('clientes.ver');
        Route::livewire('proveedores', Proveedores::class)->middleware('permiso:proveedores.gestionar')->name('proveedores.index');
        Route::get('proveedores/exportar', [OperacionesCsvController::class, 'proveedores'])->middleware('throttle:exportaciones')->middleware('permiso:proveedores.gestionar')->name('proveedores.exportar');
        Route::livewire('proveedores/{proveedor}', ProveedorFicha::class)->whereNumber('proveedor')->middleware('permiso:proveedores.gestionar')->name('proveedores.ver');

        // Agente Mozi: chat + Lienzo. Datos y vistas aisladas solo para el autor de la conversación.
        Route::livewire('agente-mozi', AgenteMoziPanel::class)->name('agente-mozi');
        Route::get('agente-mozi/datasets/{ref}', [AgenteMoziController::class, 'dataset'])->name('agente-mozi.dataset');
        Route::get('agente-mozi/datasets/{ref}/csv', [AgenteMoziController::class, 'csv'])->middleware('throttle:exportaciones')->name('agente-mozi.dataset.csv');
        Route::get('agente-mozi/artefactos/{artefacto}/vista', [AgenteMoziController::class, 'sandbox'])->whereNumber('artefacto')->name('agente-mozi.sandbox');

        Route::livewire('caja', Arqueo::class)->name('caja');
        Route::get('caja/exportar', [ReportesCsvController::class, 'arqueo'])->middleware('throttle:exportaciones')->middleware('permiso:caja.supervisar')->name('caja.exportar');
        Route::get('caja/imprimir', [ImprimirController::class, 'caja'])->middleware('permiso:caja.supervisar')->name('caja.imprimir');
        Route::livewire('reportes', ReportesPanel::class)->middleware('permiso:reportes.ver')->name('reportes');
        Route::get('reportes/exportar', [ReportesCsvController::class, 'reportes'])->middleware(['permiso:reportes.ver', 'throttle:exportaciones'])->name('reportes.exportar');

        // Suscripción a MoziStudios: no existe en el modo standalone.
        Route::middleware('saas')->group(function () {
            Route::livewire('suscripcion', MiSuscripcion::class)->name('suscripcion');
            Route::get('suscripcion/comprobantes/{pago}', ComprobantePagoController::class)->whereNumber('pago')->name('suscripcion.comprobante');
            Route::get('suscripcion/qr', QrCobroController::class)->name('suscripcion.qr');
        });
    });

    // Panel global de MoziStudios: rutas, componentes y autorización separados.
    Route::middleware('superadmin')->prefix('admin')->name('admin.')->group(function () {
        Route::livewire('/', Panel::class)->name('panel');
        Route::livewire('empresas', Empresas::class)->name('empresas');
        Route::livewire('empresas/{empresa}/facturacion', FacturacionPanel::class)->whereNumber('empresa')->name('empresas.facturacion');
        Route::livewire('migracion-cyclone', MigracionCyclone::class)->name('migracion-cyclone');
        Route::livewire('suscripciones', Suscripciones::class)->name('suscripciones');
        Route::get('suscripciones/exportar', [SuscripcionesCsvController::class, 'suscripciones'])->middleware('throttle:exportaciones')->name('suscripciones.exportar');
        Route::livewire('comprobantes', Comprobantes::class)->name('comprobantes');
        Route::get('comprobantes/exportar', [SuscripcionesCsvController::class, 'comprobantes'])->middleware('throttle:exportaciones')->name('comprobantes.exportar');
        Route::get('comprobantes/{pago}/archivo', ComprobantePagoController::class)->whereNumber('pago')->name('comprobantes.archivo');
        Route::livewire('catalogos', AdminCatalogosSiat::class)->name('catalogos');
        Route::livewire('agente-mozi', AdminAgenteMozi::class)->name('agente-mozi');
        Route::livewire('sitio-web', AdminSitioWeb::class)->name('sitio-web');
        Route::livewire('ajustes', AjustesGlobales::class)->name('ajustes');
        Route::get('ajustes/qr', QrCobroController::class)->name('ajustes.qr');
    });
});

require __DIR__.'/settings.php';
