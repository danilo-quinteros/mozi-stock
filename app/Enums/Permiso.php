<?php

namespace App\Enums;

/**
 * Catálogo cerrado de permisos de empresa (ESPECIFICACION_PERMISOS.md § 4). La empresa solo los combina
 * en roles y permisos extra; nunca crea claves nuevas. Las funciones exclusivas del administrador (§ 4.3)
 * no están aquí: no se pueden asignar.
 */
enum Permiso: string
{
    // Pedidos y ventas
    case VentasVer = 'ventas.ver';
    case VentasVender = 'ventas.vender';
    case VentasAsignarVendedor = 'ventas.asignar_vendedor';
    case VentasDevolver = 'ventas.devolver';
    case DespachosAutorizar = 'despachos.autorizar';
    case EntregasCorregir = 'entregas.corregir';

    // Créditos y cobros
    case CreditosVer = 'creditos.ver';
    case CreditosCobrar = 'creditos.cobrar';
    case CreditosCrear = 'creditos.crear';
    case CobrosAnular = 'cobros.anular';

    // Caja
    case CajaOperar = 'caja.operar';
    case CajaSupervisar = 'caja.supervisar';

    // Finanzas
    case EgresosGestionar = 'egresos.gestionar';
    case CuentasPagarGestionar = 'cuentas_pagar.gestionar';

    // Inventario
    case ProductosGestionar = 'productos.gestionar';
    case ComprasGestionar = 'compras.gestionar';
    case ProveedoresGestionar = 'proveedores.gestionar';
    case InventarioHistorial = 'inventario.historial';
    case InventarioAjustar = 'inventario.ajustar';
    case InventarioTraspasar = 'inventario.traspasar';
    case InventarioVencimientos = 'inventario.vencimientos';
    case ProduccionGestionar = 'produccion.gestionar';

    // Clientes
    case ClientesGestionar = 'clientes.gestionar';
    case ClientesDesactivar = 'clientes.desactivar';

    // Reportes y alcance
    case ReportesVer = 'reportes.ver';
    case SucursalesTodas = 'sucursales.todas';

    public function etiqueta(): string
    {
        return match ($this) {
            self::VentasVer => __('Ver pedidos y ventas'),
            self::VentasVender => __('Crear pedidos y vender'),
            self::VentasAsignarVendedor => __('Elegir el vendedor del pedido'),
            self::VentasDevolver => __('Registrar devoluciones'),
            self::DespachosAutorizar => __('Autorizar despachos'),
            self::EntregasCorregir => __('Corregir entregas'),
            self::CreditosVer => __('Ver cuentas por cobrar'),
            self::CreditosCobrar => __('Registrar abonos'),
            self::CreditosCrear => __('Crear cuentas por cobrar'),
            self::CobrosAnular => __('Anular cobros'),
            self::CajaOperar => __('Usar su caja'),
            self::CajaSupervisar => __('Supervisar cajas'),
            self::EgresosGestionar => __('Egresos'),
            self::CuentasPagarGestionar => __('Cuentas por pagar'),
            self::ProductosGestionar => __('Gestionar productos'),
            self::ComprasGestionar => __('Compras'),
            self::ProveedoresGestionar => __('Proveedores'),
            self::InventarioHistorial => __('Ver ajustes y traspasos'),
            self::InventarioAjustar => __('Registrar ajustes'),
            self::InventarioTraspasar => __('Registrar traspasos'),
            self::InventarioVencimientos => __('Vencimientos y lotes'),
            self::ProduccionGestionar => __('Producción'),
            self::ClientesGestionar => __('Crear y editar clientes'),
            self::ClientesDesactivar => __('Activar y desactivar clientes'),
            self::ReportesVer => __('Reportes'),
            self::SucursalesTodas => __('Trabajar en todas las sucursales'),
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::VentasVer => __('Lista y detalle de pedidos y ventas, marcar Entregado y panel de cocina.'),
            self::VentasVender => __('Crear y editar pedidos pendientes, confirmar ventas, POS y condiciones de crédito.'),
            self::VentasAsignarVendedor => __('Asignar el pedido a otro usuario que venda en esa sucursal.'),
            self::VentasDevolver => __('Devoluciones de ventas.'),
            self::DespachosAutorizar => __('Marcar Despachado y «Despachar y entregar».'),
            self::EntregasCorregir => __('Deshacer el último cambio de entrega y cancelar una venta despachada y devuelta.'),
            self::CreditosVer => __('Créditos, cuentas por cobrar e historial de pagos de la empresa.'),
            self::CreditosCobrar => __('Abonos a créditos y cuentas por cobrar, en su sucursal y caja.'),
            self::CreditosCrear => __('Cuentas por cobrar manuales.'),
            self::CobrosAnular => __('Anular cobros y cuentas por cobrar.'),
            self::CajaOperar => __('Abrir y cerrar turno en su caja asignada; pantalla Mi caja.'),
            self::CajaSupervisar => __('Caja y arqueo, elegir cualquier caja, cerrar turnos ajenos y registrar efectivo fuera de caja.'),
            self::EgresosGestionar => __('Ver y registrar egresos y sus categorías.'),
            self::CuentasPagarGestionar => __('Ver, crear, pagar y anular cuentas por pagar.'),
            self::ProductosGestionar => __('Crear y editar productos, precios, categorías, unidades, modelos, tallas y colores.'),
            self::ComprasGestionar => __('Ver y registrar compras con proveedores existentes.'),
            self::ProveedoresGestionar => __('Crear y editar proveedores.'),
            self::InventarioHistorial => __('Historial de operaciones de inventario y su CSV.'),
            self::InventarioAjustar => __('Entradas, salidas y ajustes de stock.'),
            self::InventarioTraspasar => __('Traspasos desde su sucursal a cualquier sucursal activa.'),
            self::InventarioVencimientos => __('Vencimientos, trazabilidad y análisis de lotes.'),
            self::ProduccionGestionar => __('Órdenes, envasado, recetas, materias primas y reportes de producción.'),
            self::ClientesGestionar => __('Alta y edición de clientes.'),
            self::ClientesDesactivar => __('Cambiar el estado del cliente.'),
            self::ReportesVer => __('Reportes generales, sus CSV y el panel de Inicio de administración.'),
            self::SucursalesTodas => __('Elegir la sucursal activa y operar en todas. Sin este permiso, solo su sucursal asignada.'),
        };
    }

    /** Grupo de la pantalla de roles (§ 4.2). */
    public function grupo(): string
    {
        return match ($this) {
            self::VentasVer, self::VentasVender, self::VentasAsignarVendedor, self::VentasDevolver,
            self::DespachosAutorizar, self::EntregasCorregir => __('Pedidos y ventas'),
            self::CreditosVer, self::CreditosCobrar, self::CreditosCrear, self::CobrosAnular => __('Créditos y cobros'),
            self::CajaOperar, self::CajaSupervisar => __('Caja'),
            self::EgresosGestionar, self::CuentasPagarGestionar => __('Finanzas'),
            self::ProductosGestionar, self::ComprasGestionar, self::ProveedoresGestionar, self::InventarioHistorial,
            self::InventarioAjustar, self::InventarioTraspasar, self::InventarioVencimientos, self::ProduccionGestionar => __('Inventario'),
            self::ClientesGestionar, self::ClientesDesactivar => __('Clientes'),
            self::ReportesVer, self::SucursalesTodas => __('Reportes y alcance'),
        };
    }

    /**
     * Permisos que este necesita (§ 4.4). Al guardar, el servidor los agrega aunque no lleguen.
     *
     * @return list<self>
     */
    public function requiere(): array
    {
        return match ($this) {
            self::VentasVender, self::VentasDevolver, self::DespachosAutorizar, self::EntregasCorregir => [self::VentasVer],
            self::VentasAsignarVendedor => [self::VentasVender],
            self::CreditosCobrar, self::CreditosCrear, self::CobrosAnular => [self::CreditosVer],
            self::InventarioAjustar, self::InventarioTraspasar => [self::InventarioHistorial],
            self::ClientesDesactivar => [self::ClientesGestionar],
            default => [],
        };
    }

    /**
     * Capacidades de la empresa de las que depende (§ 4.5): basta con una activa. Vacío = no depende.
     *
     * @return list<Capacidad>
     */
    public function capacidades(): array
    {
        return match ($this) {
            self::DespachosAutorizar => [Capacidad::Despachos],
            self::EntregasCorregir => [Capacidad::Despachos, Capacidad::Cocina],
            self::InventarioVencimientos => [Capacidad::LotesVencimientos],
            self::ProduccionGestionar => [Capacidad::Produccion],
            default => [],
        };
    }

    /**
     * El conjunto con todos sus requeridos (cierre transitivo), sin repetir y en el orden del catálogo.
     *
     * @param  iterable<self>  $permisos
     * @return list<self>
     */
    public static function conRequeridos(iterable $permisos): array
    {
        $claves = [];
        $pendientes = [...$permisos];

        while ($pendientes !== []) {
            $permiso = array_pop($pendientes);

            if (isset($claves[$permiso->value])) {
                continue;
            }

            $claves[$permiso->value] = true;
            array_push($pendientes, ...$permiso->requiere());
        }

        return array_values(array_filter(self::cases(), fn (self $p): bool => isset($claves[$p->value])));
    }

    /**
     * Claves que dan este permiso: la suya y las de los permisos que lo requieren (p. ej. ventas.vender da ventas.ver).
     * Para buscar en SQL a los usuarios que lo tienen.
     *
     * @return list<string>
     */
    public function clavesQueLoDan(): array
    {
        return array_values(array_map(
            fn (self $p): string => $p->value,
            array_filter(self::cases(), fn (self $p): bool => in_array($this, self::conRequeridos([$p]), true)),
        ));
    }

    /**
     * Convierte claves recibidas (petición o base de datos) en permisos. Las desconocidas se descartan.
     *
     * @param  iterable<mixed>  $claves
     * @return list<self>
     */
    public static function desdeClaves(iterable $claves): array
    {
        $permisos = [];

        foreach ($claves as $clave) {
            $permiso = is_string($clave) ? self::tryFrom($clave) : null;

            if ($permiso !== null) {
                $permisos[$permiso->value] = $permiso;
            }
        }

        return array_values($permisos);
    }

    /**
     * Catálogo agrupado para la pantalla de roles, en el orden de § 4.2.
     *
     * @return array<string, list<self>>
     */
    public static function porGrupo(): array
    {
        $grupos = [];

        foreach (self::cases() as $permiso) {
            $grupos[$permiso->grupo()][] = $permiso;
        }

        return $grupos;
    }

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(fn (self $p): string => $p->value, self::cases());
    }

    /**
     * Rol por defecto «Vendedor» (§ 5): exactamente lo que hoy puede hacer un vendedor.
     *
     * @return list<self>
     */
    public static function deVendedor(): array
    {
        return [self::VentasVer, self::VentasVender, self::CreditosVer, self::CreditosCobrar, self::CajaOperar, self::ClientesGestionar];
    }

    /**
     * Rol por defecto «Encargado de sucursal» (§ 5): todo dentro de su sucursal, sin productos, precios,
     * proveedores, cuentas por pagar, cuentas por cobrar manuales ni producción.
     *
     * @return list<self>
     */
    public static function deEncargado(): array
    {
        return [
            ...self::deVendedor(),
            self::VentasAsignarVendedor, self::VentasDevolver, self::DespachosAutorizar, self::EntregasCorregir,
            self::CobrosAnular, self::CajaSupervisar, self::EgresosGestionar, self::ComprasGestionar,
            self::InventarioHistorial, self::InventarioAjustar, self::InventarioTraspasar, self::InventarioVencimientos,
            self::ClientesDesactivar, self::ReportesVer,
        ];
    }
}
