<?php

namespace App\Domain\Ventas\Actions;

use App\Domain\Facturacion\DatosFactura;
use App\Domain\Inventario\LibroStock;
use App\Domain\Tenancy\ResolverSucursal;
use App\Enums\EstadoPedido;
use App\Models\Pedido;
use App\Models\Scopes\EmpresaScope;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Venta directa desde el punto de venta (POS): el carrito se guarda como pedido y se confirma
 * en la misma transacción, con las mismas acciones que Pedidos y ventas (precios del catálogo,
 * calculadora, stock por sucursal, cobros y factura). Si algo falla no queda ningún pedido
 * pendiente. Repetir la solicitud con la misma clave devuelve la misma venta.
 */
final class VenderEnPos
{
    public function __construct(
        private readonly GuardarPedido $pedidos,
        private readonly ConfirmarVenta $ventas,
        private readonly ResolverSucursal $resolverSucursal,
        private readonly LibroStock $libro,
    ) {}

    /**
     * @param  array{sucursal_id: int|null, cliente_id: int|null, lineas: list<array<string, mixed>>, descuento_global_tipo: string, descuento_global_valor: string|null, clave: string}  $datos
     * @param  array{metodo: string|null, en_cuotas: bool, cuota_inicial: string|null, efectivo: string|null, electronico: string|null, clave: string}  $pago
     */
    public function ejecutar(User $actor, array $datos, array $pago, ?DatosFactura $factura = null): Venta
    {
        Gate::forUser($actor)->authorize('create', Pedido::class);
        $empresaId = (int) $actor->empresa_id;

        if (($venta = $this->ventaPrevia($actor, $empresaId, $datos['clave'])) !== null) {
            return $venta;
        }

        // Filas de saldo fuera de la transacción (LibroStock); solo productos de la empresa.
        $sucursal = $this->resolverSucursal->paraOperacion($actor, $datos['sucursal_id']);
        $productos = DB::table('productos')->where('empresa_id', $empresaId)
            ->whereIn('id', array_map(fn (array $l) => (int) ($l['producto_id'] ?? 0), $datos['lineas']))
            ->pluck('id')->all();
        $this->libro->asegurarNiveles($empresaId, array_values(array_map(
            fn (int $productoId) => ['sucursal_id' => $sucursal->id, 'producto_id' => $productoId], $productos,
        )));

        return DB::transaction(function () use ($actor, $datos, $pago, $factura): Venta {
            $pedido = $this->pedidos->ejecutar($actor, null, $datos + [
                'vendedor_id' => null,
                'nota' => null,
                'pago_metodo' => $pago['metodo'],
                'pago_en_cuotas' => $pago['en_cuotas'],
                'pago_cuota_inicial' => $pago['cuota_inicial'],
                'pago_efectivo' => $pago['efectivo'],
                'pago_electronico' => $pago['electronico'],
            ]);

            return $this->ventas->ejecutar($actor, $pedido, $pago, $factura);
        }, attempts: 3);
    }

    private function ventaPrevia(User $actor, int $empresaId, string $clave): ?Venta
    {
        $pedido = Pedido::withoutGlobalScope(EmpresaScope::class)
            ->where('empresa_id', $empresaId)->where('clave_idempotencia', $clave)->first();

        if ($pedido === null || $pedido->estado !== EstadoPedido::Vendido) {
            return null;
        }

        Gate::forUser($actor)->authorize('view', $pedido);

        return Venta::query()->where('pedido_id', $pedido->id)->first();
    }
}
