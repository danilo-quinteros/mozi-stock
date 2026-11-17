<?php

namespace App\Domain\CatalogoExterno\Actions;

use App\Domain\Catalogo\Actions\AsignarPreciosIniciales;
use App\Domain\Catalogo\Actions\GuardarProducto;
use App\Domain\Inventario\StockInicial;
use App\Models\CatalogoExternoProducto;
use App\Models\EmpresaProveedorExterno;
use App\Models\Producto;
use App\Models\ProductoVinculoExterno;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Agrega al catálogo de la empresa un producto del catálogo de un proveedor externo (Cyclone) desde otra pantalla
 * (compras, ajustes): ejecutar() usa SincronizarEmpresa, que lo crea con precio 0 y sin stock, o reactiva el que se
 * dejó de vender, sin tocar precios, costos ni existencias. «Agregar» en la pestaña del proveedor usa
 * agregarConPrecioYStock(), que además fija el precio de venta y el stock inicial.
 *
 * Solo quien puede crear productos (administrador) y solo si la empresa está vinculada y activa con ese proveedor;
 * el id del catálogo que llega de la pantalla no concede acceso por sí mismo. Repetir la petición (doble clic) no
 * crea dos productos: el vínculo es único por empresa, proveedor y producto externo.
 */
final class AgregarProductoExterno
{
    public function __construct(
        private readonly SincronizarEmpresa $sincronizador,
        private readonly GuardarProducto $productos,
        private readonly StockInicial $stockInicial,
    ) {}

    /**
     * «Agregar» en Productos › Catálogo Cyclone: el producto entra al catálogo con su precio de venta (obligatorio,
     * mayor a 0) y su stock inicial (obligatorio; 0 = todavía sin unidades). Todo en una transacción: si el precio o
     * la entrada «Inventario inicial» fallan, el producto tampoco queda creado.
     *
     * - el precio pasa por GuardarProducto::actualizarPrecioConsumidor (Policy y auditoría); si ya es ese, no se toca;
     * - con cantidad > 0, StockInicial registra la entrada en la sucursal elegida (permiso de inventario, sucursal activa
     *   de la empresa, cantidades enteras si se vende por unidades). Repetir la clave no repite la entrada.
     *
     * Errores en `precio`, `stock_inicial`, `stock_sucursal_id` y `stock_lote`.
     */
    public function agregarConPrecioYStock(User $actor, int $catalogoExternoId, string $precio, string $cantidad, int|string|null $sucursalId, string $clave): Producto
    {
        Gate::forUser($actor)->authorize('create', Producto::class);

        $precioValido = AsignarPreciosIniciales::precio($precio);
        $cantidad = trim($cantidad);
        $stock = $cantidad === '' ? null : Decimal::normalizar($cantidad, Decimal::ESCALA_CANTIDAD);
        $errores = [];

        if ($precioValido === null) {
            $errores['precio'] = trim($precio) === ''
                ? __('Escribe el precio de venta.')
                : __('El precio de venta debe ser mayor a 0, con hasta 2 decimales.');
        }

        if ($cantidad === '') {
            $errores['stock_inicial'] = __('Escribe el stock inicial. Si todavía no tienes unidades, puedes poner 0.');
        } elseif ($stock === null || Decimal::comparar($stock, '0') < 0) {
            $errores['stock_inicial'] = __('El stock inicial debe ser 0 o más.');
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        $conStock = Decimal::comparar($stock, '0') > 0;
        $preparado = $conStock ? $this->stockInicial->preparar($actor, ['sucursal_id' => $sucursalId, 'clave' => $clave]) : null;

        // Reintento o doble clic con la misma clave: la entrada ya existe, no se repite nada.
        if ($preparado !== null && ($hecho = $this->registradoCon($actor, $clave)) !== null) {
            return $hecho;
        }

        try {
            return $this->agregarEnTransaccion($actor, $catalogoExternoId, $precioValido, $stock, $preparado);
        } catch (UniqueConstraintViolationException $e) {
            // Dos peticiones simultáneas con la misma clave: gana la primera y esta devuelve su producto.
            return ($preparado !== null ? $this->registradoCon($actor, $clave) : null) ?? throw $e;
        }
    }

    private function registradoCon(User $actor, string $clave): ?Producto
    {
        $productoId = $this->stockInicial->productoDe((int) $actor->empresa_id, $clave);

        return $productoId === null ? null : Producto::query()->whereKey($productoId)->first();
    }

    /**
     * @param  array{sucursal_id: int, clave: string, lote: mixed, vencimiento: mixed}|null  $preparado
     */
    private function agregarEnTransaccion(User $actor, int $catalogoExternoId, string $precioValido, string $stock, ?array $preparado): Producto
    {
        return DB::transaction(function () use ($actor, $catalogoExternoId, $precioValido, $stock, $preparado): Producto {
            $producto = Producto::query()->whereKey($this->ejecutar($actor, $catalogoExternoId)->id)->lockForUpdate()->firstOrFail();

            if (Decimal::comparar((string) $producto->precio_consumidor, $precioValido, Decimal::ESCALA_DINERO) !== 0) {
                $producto = $this->productos->actualizarPrecioConsumidor($actor, $producto, $precioValido);
            }

            if ($preparado !== null) {
                if (! $producto->controla_stock) {
                    throw ValidationException::withMessages(['stock_inicial' => __('Un producto sin control de stock no lleva stock inicial.')]);
                }

                $this->stockInicial->aplicar($actor, $preparado, [['producto_id' => $producto->id, 'cantidad' => $stock]],
                    __('Stock inicial de :producto', ['producto' => $producto->nombre]));
            }

            return $producto;
        });
    }

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
