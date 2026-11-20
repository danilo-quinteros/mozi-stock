<?php

namespace App\Policies;

use App\Domain\Tenancy\CapacidadesEmpresa;
use App\Enums\Capacidad;
use App\Enums\Permiso;
use App\Models\User;
use App\Models\Venta;

class VentaPolicy
{
    /**
     * Ventas de su alcance (ESPECIFICACION_PERMISOS.md § 6.2) con ventas.ver: toda la empresa para quien trabaja en
     * todas las sucursales, su sucursal asignada para el resto.
     */
    public function view(User $user, Venta $venta): bool
    {
        return $user->empresa_id === $venta->empresa_id
            && $user->tienePermiso(Permiso::VentasVer)
            && $user->alcanzaSucursal($venta->sucursal_id);
    }

    /**
     * Historial de pagos de un crédito (creditos.ver): cualquier venta de la empresa, como los cobros.
     */
    public function verCredito(User $user, Venta $venta): bool
    {
        return $user->tienePermiso(Permiso::CreditosVer) && $user->empresa_id === $venta->empresa_id;
    }

    /**
     * Decisión del propietario (23/10/2026): quien registra abonos cobra cualquier crédito de la empresa,
     * sin importar su alcance; el cobro queda en su sucursal y a su nombre.
     */
    public function cobrar(User $user, Venta $venta): bool
    {
        return $user->tienePermiso(Permiso::CreditosCobrar) && $user->empresa_id === $venta->empresa_id;
    }

    /**
     * Vencimiento único o plan de cuotas de un crédito: quien vende y puede ver la venta.
     */
    public function condicionesCredito(User $user, Venta $venta): bool
    {
        return $user->tienePermiso(Permiso::VentasVender) && $this->view($user, $venta);
    }

    /**
     * Devoluciones de ventas de su alcance: permiso ventas.devolver.
     */
    public function devolver(User $user, Venta $venta): bool
    {
        return $user->tienePermiso(Permiso::VentasDevolver) && $this->view($user, $venta);
    }

    /**
     * Autorizar la salida del almacén (ESPECIFICACION_DESPACHOS.md § 5.2): permiso despachos.autorizar (solo cuenta con
     * la capacidad Despachos) en ventas de su alcance.
     */
    public function despachar(User $user, Venta $venta): bool
    {
        return $this->conDespachos($user)
            && $user->tienePermiso(Permiso::DespachosAutorizar)
            && $this->view($user, $venta);
    }

    /**
     * Marcar entregado: cualquiera que pueda ver la venta (despachos § 5.3, cocina § 5.2).
     */
    public function marcarEntregada(User $user, Venta $venta): bool
    {
        return $this->view($user, $venta) && $this->conEntregas($user);
    }

    /**
     * Deshacer el último cambio de entrega o cancelar una venta despachada y devuelta (despachos § 3 y § 5.5,
     * cocina § 5.3): permiso entregas.corregir en ventas de su alcance.
     */
    public function corregirEntrega(User $user, Venta $venta): bool
    {
        return $user->tienePermiso(Permiso::EntregasCorregir) && $this->view($user, $venta) && $this->conEntregas($user);
    }

    /**
     * Deshacer una entrega durante 10 segundos (ESPECIFICACION_COCINA.md § 5.3), con la capacidad «cocina».
     * Que sea quien la marcó y el plazo medido en el servidor los comprueba la acción con la venta bloqueada:
     * así un doble clic recibe un motivo y no un 403.
     */
    public function deshacerEntrega(User $user, Venta $venta): bool
    {
        return $this->view($user, $venta) && $this->con($user, Capacidad::Cocina);
    }

    /**
     * Sin la capacidad no existe ninguna acción de entrega, aunque alguien llame a la acción directamente.
     */
    private function conDespachos(User $user): bool
    {
        return $this->con($user, Capacidad::Despachos);
    }

    /**
     * Entregar y corregir existen con despachos o con cocina; sin ninguna de las dos, no.
     */
    private function conEntregas(User $user): bool
    {
        return $user->empresa_id !== null && app(CapacidadesEmpresa::class)->flujoEntrega($user->empresa_id) !== null;
    }

    private function con(User $user, Capacidad $capacidad): bool
    {
        return $user->empresa_id !== null && app(CapacidadesEmpresa::class)->tiene($user->empresa_id, $capacidad);
    }
}
