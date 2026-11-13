<?php

namespace App\Policies;

use App\Domain\Tenancy\CapacidadesEmpresa;
use App\Enums\Capacidad;
use App\Models\User;
use App\Models\Venta;

class VentaPolicy
{
    public function view(User $user, Venta $venta): bool
    {
        return $user->empresa_id === $venta->empresa_id
            && ($user->esAdministrador() || ($user->esVendedor() && $user->sucursal_id === $venta->sucursal_id));
    }

    /**
     * Decisión del propietario (23/10/2026): cualquier usuario de la empresa cobra cualquier
     * crédito de ella; el cobro queda en su sucursal y a su nombre.
     */
    public function cobrar(User $user, Venta $venta): bool
    {
        return $user->perteneceAEmpresa() && $user->empresa_id === $venta->empresa_id;
    }

    /**
     * Vencimiento único o plan de cuotas de un crédito: el administrador, o el vendedor que
     * puede ver la venta (de su sucursal).
     */
    public function condicionesCredito(User $user, Venta $venta): bool
    {
        return $this->view($user, $venta);
    }

    /**
     * Decisión del propietario (23/10/2026): solo el administrador registra devoluciones.
     */
    public function devolver(User $user, Venta $venta): bool
    {
        return $user->esAdministrador() && $user->empresa_id === $venta->empresa_id;
    }

    /**
     * Autorizar la salida del almacén (ESPECIFICACION_DESPACHOS.md § 5.2, decisión D4): un administrador,
     * o un vendedor con permiso de almacén cuya sucursal asignada (inmutable para él) sea la de la venta.
     */
    public function despachar(User $user, Venta $venta): bool
    {
        return $user->empresa_id === $venta->empresa_id
            && $this->conDespachos($user)
            && ($user->esAdministrador()
                || ($user->esVendedor() && $user->puede_despachar && $user->sucursal_id === $venta->sucursal_id));
    }

    /**
     * Marcar entregado: cualquiera que pueda ver la venta (despachos § 5.3, cocina § 5.2).
     */
    public function marcarEntregada(User $user, Venta $venta): bool
    {
        return $this->view($user, $venta) && $this->conEntregas($user);
    }

    /**
     * Deshacer el último cambio de entrega o cancelar una venta despachada y devuelta: solo el administrador
     * (despachos § 3 y § 5.5, cocina § 5.3).
     */
    public function corregirEntrega(User $user, Venta $venta): bool
    {
        return $user->esAdministrador() && $user->empresa_id === $venta->empresa_id && $this->conEntregas($user);
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
