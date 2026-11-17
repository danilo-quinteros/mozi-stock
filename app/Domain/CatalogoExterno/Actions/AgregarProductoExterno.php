<?php

namespace App\Domain\CatalogoExterno\Actions;

use App\Models\CatalogoExternoProducto;
use App\Models\EmpresaProveedorExterno;
use App\Models\Producto;
use App\Models\ProductoVinculoExterno;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Agrega al catálogo de la empresa un producto del catálogo de un proveedor externo (Cyclone) desde otra pantalla
 * (compras, ajustes). Es lo mismo que «Agregar» en la pestaña del proveedor: SincronizarEmpresa lo crea con precio 0
 * y sin stock, o reactiva el que se dejó de vender. Nunca toca precios, costos ni existencias.
 *
 * Solo quien puede crear productos (administrador) y solo si la empresa está vinculada y activa con ese proveedor;
 * el id del catálogo que llega de la pantalla no concede acceso por sí mismo. Repetir la petición (doble clic) no
 * crea dos productos: el vínculo es único por empresa, proveedor y producto externo.
 */
final class AgregarProductoExterno
{
    public function __construct(private readonly SincronizarEmpresa $sincronizador) {}

    public function ejecutar(User $actor, int $catalogoExternoId): Producto
    {
        Gate::forUser($actor)->authorize('create', Producto::class);

        $externo = CatalogoExternoProducto::query()->with('proveedor')->whereKey($catalogoExternoId)->where('disponible', true)->first();
        $vinculada = $externo !== null && $externo->proveedor->activo && EmpresaProveedorExterno::withoutGlobalScopes()
            ->where('empresa_id', $actor->empresa_id)
            ->where('proveedor_externo_id', $externo->proveedor_externo_id)
            ->where('activo', true)
            ->exists();

        if ($externo === null || ! $vinculada || $actor->empresa === null) {
            throw ValidationException::withMessages(['catalogo_externo' => __('Ese producto ya no está disponible en el catálogo del proveedor.')]);
        }

        try {
            $this->sincronizador->ejecutar($actor->empresa, $externo->proveedor, $actor, soloExternoIds: [$externo->externo_id]);
        } catch (UniqueConstraintViolationException) {
            // Otra petición lo agregó al mismo tiempo: se usa ese producto.
        }

        $productoId = ProductoVinculoExterno::withoutGlobalScopes()
            ->where('empresa_id', $actor->empresa_id)
            ->where('proveedor_externo_id', $externo->proveedor_externo_id)
            ->where('externo_id', $externo->externo_id)
            ->value('producto_id');

        $producto = $productoId === null ? null : Producto::withoutGlobalScopes()
            ->where('empresa_id', $actor->empresa_id)->whereKey($productoId)->first();

        if ($producto === null) {
            throw ValidationException::withMessages(['catalogo_externo' => __('No se pudo agregar el producto. Inténtalo de nuevo.')]);
        }

        return $producto;
    }
}
