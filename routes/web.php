<?php

use App\Http\Controllers\AgenteMoziController;
use App\Http\Controllers\CatalogoExternoFotoController;
use App\Http\Controllers\ComprobanteCuentaController;
use App\Http\Controllers\ComprobantePagoController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExportarMovimientosController;
use App\Http\Controllers\ExportarPedidosController;
use App\Http\Controllers\ExportarProductosController;
use App\Http\Controllers\ExportarUsuariosController;
use App\Http\Controllers\FacturaSiatController;
use App\Http\Controllers\IdiomaController;
use App\Http\Controllers\ImpersonacionController;
use App\Http\Controllers\ImprimirController;
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
use App\Livewire\Admin\Panel;
use App\Livewire\Admin\SitioWeb as AdminSitioWeb;
use App\Livewire\Admin\Suscripciones;
use App\Livewire\AgenteMozi\Panel as AgenteMoziPanel;
use App\Livewire\Caja\Arqueo;
use App\Livewire\Catalogo\ImportarProductos;
use App\Livewire\Catalogo\ProductoFormulario;
use App\Livewire\Catalogo\Productos;
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
use App\Livewire\CuentasPagar\NuevaObligacion;
use App\Livewire\Egresos\Panel as EgresosPanel;
use App\Livewire\Empresa\Configuracion;
use App\Livewire\Empresa\MiSuscripcion;
use App\Livewire\Empresa\Sucursales;
use App\Livewire\Empresa\Usuarios;
use App\Livewire\Facturacion\Panel as FacturacionPanel;
use App\Livewire\Inventario\Movimientos;
use App\Livewire\Inventario\NuevoAjuste;
use App\Livewire\Inventario\NuevoTraspaso;
use App\Livewire\Pedidos\Editor as PedidoEditor;
use App\Livewire\Pedidos\Lista as PedidoLista;
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

    // Panel de empresa: el contexto tenant sale del usuario autenticado, nunca de la URL.
    Route::middleware('empresa')->group(function () {
        Route::livewire('sucursales', Sucursales::class)->name('sucursales.index');
        // Las cajas se gestionan dentro de Sucursales y cajas; el enlace antiguo solo redirige.
        Route::redirect('sucursales/{sucursal}/cajas', '/sucursales')->whereNumber('sucursal')->name('sucursales.cajas');
        Route::livewire('usuarios', Usuarios::class)->name('usuarios.index');
        Route::get('usuarios/exportar', ExportarUsuariosController::class)->middleware('throttle:exportaciones')->name('usuarios.exportar');
        Route::livewire('configuracion', Configuracion::class)->name('configuracion');
        // Facturación SIAT: siempre la empresa del usuario; el Gate exige que MoziStudios la haya habilitado.
        Route::livewire('facturacion', FacturacionPanel::class)->name('facturacion');
        Route::post('sucursal-activa', SucursalActivaController::class)->name('sucursal-activa');

        Route::livewire('productos', Productos::class)->name('productos.index');
        Route::get('productos/exportar', ExportarProductosController::class)->middleware('throttle:exportaciones')->name('productos.exportar');
        Route::livewire('productos/nuevo', ProductoFormulario::class)->name('productos.crear');
        Route::livewire('productos/importar', ImportarProductos::class)->name('productos.importar');
        Route::livewire('productos/{producto}/editar', ProductoFormulario::class)->name('productos.editar');
        Route::get('productos/{producto}/imagen', ProductoImagenController::class)->name('productos.imagen');

        Route::livewire('inventario', Movimientos::class)->name('inventario.movimientos');
        Route::get('inventario/exportar', ExportarMovimientosController::class)->middleware('throttle:exportaciones')->name('inventario.exportar');
        Route::livewire('inventario/ajuste', NuevoAjuste::class)->name('inventario.ajuste');
        Route::livewire('inventario/traspaso', NuevoTraspaso::class)->name('inventario.traspaso');

        // Punto de venta: guarda y confirma en un paso con las mismas acciones de Pedidos y ventas.
        Route::livewire('pos', Pos::class)->name('pos');
        Route::livewire('pedidos', PedidoLista::class)->name('pedidos.index');
        Route::get('pedidos/exportar', ExportarPedidosController::class)->middleware('throttle:exportaciones')->name('pedidos.exportar');
        Route::livewire('pedidos/nuevo', PedidoEditor::class)->name('pedidos.crear');
        Route::livewire('pedidos/{pedido}', PedidoEditor::class)->whereNumber('pedido')->name('pedidos.editar');
        Route::get('pedidos/{pedido}/imprimir', PedidoImprimirController::class)->whereNumber('pedido')->name('pedidos.imprimir');
        Route::livewire('ventas/{venta}', VentaDetalle::class)->whereNumber('venta')->name('ventas.ver');
        Route::livewire('ventas/{venta}/devolucion', NuevaDevolucion::class)->whereNumber('venta')->name('ventas.devolucion');
        // Solo facturas validadas por el SIN; EmpresaScope en el binding y VentaPolicy en el controlador.
        Route::get('facturas/{factura}/imprimir', [FacturaSiatController::class, 'imprimir'])->whereNumber('factura')->name('facturas.imprimir');
        Route::get('facturas/{factura}/xml', [FacturaSiatController::class, 'xml'])->whereNumber('factura')->name('facturas.xml');

        Route::livewire('creditos', CreditoLista::class)->name('creditos.index');
        Route::get('creditos/exportar', [ReportesCsvController::class, 'creditos'])->middleware('throttle:exportaciones')->name('creditos.exportar');
        Route::livewire('creditos/{venta}', CreditoHistorial::class)->whereNumber('venta')->name('creditos.historial');
        Route::get('creditos/{venta}/exportar', [ReportesCsvController::class, 'historial'])->whereNumber('venta')->middleware('throttle:exportaciones')->name('creditos.historial.exportar');
        Route::get('creditos/{venta}/imprimir', [ImprimirController::class, 'credito'])->whereNumber('venta')->name('creditos.imprimir');
        // Cuentas por cobrar: cobro a un cliente, cuentas manuales y comprobantes privados.
        Route::livewire('creditos/cobrar', CreditoCobrar::class)->name('creditos.cobrar');
        Route::livewire('creditos/cuentas/{cuenta}', CreditoCuenta::class)->whereNumber('cuenta')->name('creditos.cuenta');
        Route::get('cobros/{cobro}/comprobante', [ComprobanteCuentaController::class, 'cobro'])->whereNumber('cobro')->name('cobros.comprobante');

        // Cuentas por pagar (solo administradores; CuentaPagarPolicy).
        Route::livewire('cuentas-por-pagar', CuentaPagarLista::class)->name('cuentas-pagar.index');
        Route::livewire('cuentas-por-pagar/nueva', NuevaObligacion::class)->name('cuentas-pagar.crear');
        Route::get('cuentas-por-pagar/exportar', [ReportesCsvController::class, 'cuentasPagar'])->middleware('throttle:exportaciones')->name('cuentas-pagar.exportar');
        Route::livewire('cuentas-por-pagar/{cuenta}', CuentaPagarDetalle::class)->whereNumber('cuenta')->name('cuentas-pagar.ver');
        Route::get('cuentas-por-pagar/{cuenta}/respaldo', [ComprobanteCuentaController::class, 'respaldo'])->whereNumber('cuenta')->name('cuentas-pagar.respaldo');
        Route::get('cuentas-por-pagar/pagos/{pago}/comprobante', [ComprobanteCuentaController::class, 'pago'])->whereNumber('pago')->name('cuentas-pagar.comprobante');

        Route::livewire('compras', CompraLista::class)->name('compras.index');
        Route::get('compras/exportar', [OperacionesCsvController::class, 'compras'])->middleware('throttle:exportaciones')->name('compras.exportar');
        Route::livewire('compras/nueva', NuevaCompra::class)->name('compras.crear');
        Route::livewire('compras/{compra}', CompraDetalle::class)->whereNumber('compra')->name('compras.ver');
        Route::get('compras/{compra}/imprimir', [ImprimirController::class, 'compra'])->whereNumber('compra')->name('compras.imprimir');

        Route::livewire('egresos', EgresosPanel::class)->name('egresos.index');
        Route::get('egresos/exportar', [OperacionesCsvController::class, 'egresos'])->middleware('throttle:exportaciones')->name('egresos.exportar');

        Route::livewire('clientes', Clientes::class)->name('clientes.index');
        Route::get('clientes/exportar', [OperacionesCsvController::class, 'clientes'])->middleware('throttle:exportaciones')->name('clientes.exportar');
        Route::livewire('clientes/{cliente}', ClienteFicha::class)->whereNumber('cliente')->name('clientes.ver');
        Route::livewire('proveedores', Proveedores::class)->name('proveedores.index');
        Route::get('proveedores/exportar', [OperacionesCsvController::class, 'proveedores'])->middleware('throttle:exportaciones')->name('proveedores.exportar');
        Route::livewire('proveedores/{proveedor}', ProveedorFicha::class)->whereNumber('proveedor')->name('proveedores.ver');

        // Agente Mozi: chat + Lienzo. Datos y vistas aisladas solo para el autor de la conversación.
        Route::livewire('agente-mozi', AgenteMoziPanel::class)->name('agente-mozi');
        Route::get('agente-mozi/datasets/{ref}', [AgenteMoziController::class, 'dataset'])->name('agente-mozi.dataset');
        Route::get('agente-mozi/datasets/{ref}/csv', [AgenteMoziController::class, 'csv'])->middleware('throttle:exportaciones')->name('agente-mozi.dataset.csv');
        Route::get('agente-mozi/artefactos/{artefacto}/vista', [AgenteMoziController::class, 'sandbox'])->whereNumber('artefacto')->name('agente-mozi.sandbox');

        Route::livewire('caja', Arqueo::class)->name('caja');
        Route::get('caja/exportar', [ReportesCsvController::class, 'arqueo'])->middleware('throttle:exportaciones')->name('caja.exportar');
        Route::get('caja/imprimir', [ImprimirController::class, 'caja'])->name('caja.imprimir');
        Route::livewire('reportes', ReportesPanel::class)->name('reportes');
        Route::get('reportes/exportar', [ReportesCsvController::class, 'reportes'])->middleware('throttle:exportaciones')->name('reportes.exportar');

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
