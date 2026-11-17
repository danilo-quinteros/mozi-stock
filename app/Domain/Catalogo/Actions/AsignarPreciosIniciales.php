<?php

namespace App\Domain\Catalogo\Actions;

use App\Models\Producto;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Precio de venta (consumidor) que el administrador escribe al comprar un producto que todavía no tiene precio, como
 * los recién agregados del catálogo de Cyclone. Solo llena precios en 0: nunca cambia un precio ya definido, aunque
 * otra persona lo haya puesto entre la pantalla y la confirmación (se relee con bloqueo dentro de la transacción).
 *
 * Solo productos de venta sueltos (una combinación usa el precio de su modelo). Cada cambio pasa por
 * GuardarProducto::actualizarPrecioConsumidor: misma Policy, validación y auditoría que la edición en Productos.
 */
final class AsignarPreciosIniciales
{
    public function __construct(private readonly GuardarProducto $productos) {}

    /**
     * Precio válido para esta pantalla: más de 0 y hasta 2 decimales; null si no lo es.
     */
    public static function precio(string $valor): ?string
    {
        $precio = Decimal::normalizar(trim($valor), Decimal::ESCALA_DINERO);

        return $precio !== null && Decimal::comparar($precio, '0', Decimal::ESCALA_DINERO) > 0 ? $precio : null;
    }

    /** Si al producto se le puede poner precio inicial (sin mirar el permiso). */
    public static function admite(Producto $producto): bool
    {
        return $producto->clase === Producto::CLASE_VENTA
            && $producto->modelo_id === null
            && Decimal::comparar((string) $producto->precio_consumidor, '0', Decimal::ESCALA_DINERO) <= 0;
    }

    /**
     * @param  array<int, string>  $precios  producto_id => precio escrito
     * @return int cuántos productos quedaron con precio
     */
    public function ejecutar(User $actor, array $precios): int
    {
        if ($precios === []) {
            return 0;
        }

        Gate::forUser($actor)->authorize('create', Producto::class);

        $validos = [];

        foreach ($precios as $productoId => $valor) {
            $precio = self::precio((string) $valor);

            if ($precio === null) {
                throw ValidationException::withMessages(['precios' => __('Revisa los precios de venta: deben ser mayores a 0 y con hasta 2 decimales.')]);
            }

            $validos[(int) $productoId] = $precio;
        }

        return DB::transaction(function () use ($actor, $validos): int {
            // El scope de empresa deja fuera productos de otra empresa aunque lleguen sus ids.
            $productos = Producto::query()->whereIn('id', array_keys($validos))->lockForUpdate()->get();
            $aplicados = 0;

            foreach ($productos as $producto) {
                if (! self::admite($producto)) {
                    continue;
                }

                $this->productos->actualizarPrecioConsumidor($actor, $producto, $validos[$producto->id]);
                $aplicados++;
            }

            return $aplicados;
        });
    }
}
