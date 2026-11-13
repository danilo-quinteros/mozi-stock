<?php

namespace App\Domain\Inventario;

use App\Domain\Tenancy\ResolverSucursal;
use App\Enums\TipoOperacionInventario;
use App\Models\OperacionInventario;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Stock inicial al crear un producto, también uno con tallas y colores (decisión del propietario, 10/11/2026): una
 * entrada «Inventario inicial» (Ajustes → Entrada, motivo `inventario_inicial`) en UNA sucursal, escrita en la misma
 * transacción que crea el producto: si algo falla no queda ni el producto ni el stock. Igual que la importación de
 * productos, no es una compra: no lleva proveedor ni costo y no toca caja ni cuentas por pagar.
 *
 * - preparar() se llama antes de crear nada: permiso de operar inventario (solo administradores), sucursal activa de la
 *   empresa (ResolverSucursal) y clave de idempotencia;
 * - aplicar() se llama dentro de la transacción del alta, con los productos ya guardados: LibroStock valida las
 *   cantidades (positivas, enteras si el producto se vende por unidades), crea los saldos y escribe la operación;
 * - un producto con lote y vencimiento exige número de lote y vencimiento (DatosLote), como cualquier entrada;
 * - repetir la clave no repite nada: la operación ya existe y productoDe() dice qué producto se creó con ella.
 *
 * Los errores van a `stock_sucursal_id`, `stock_lote` o, para las cantidades, a `stock_inicial`.
 */
final class StockInicial
{
    public function __construct(
        private readonly LibroStock $libro,
        private readonly ResolverSucursal $resolverSucursal,
    ) {}

    /**
     * @param  array{sucursal_id: int|string|null, clave: string, lote?: mixed, vencimiento?: mixed}  $datos
     * @return array{sucursal_id: int, clave: string, lote: mixed, vencimiento: mixed}
     */
    public function preparar(User $actor, array $datos): array
    {
        Gate::forUser($actor)->authorize('create', OperacionInventario::class);

        if (! Str::isUuid($datos['clave'])) {
            throw ValidationException::withMessages(['stock_inicial' => __('El formulario no tiene una clave válida. Recarga la página.')]);
        }

        $solicitada = is_numeric($datos['sucursal_id']) ? (int) $datos['sucursal_id'] : null;

        try {
            $sucursal = $this->resolverSucursal->paraOperacion($actor, $solicitada);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['stock_sucursal_id' => __('Elige la sucursal donde está el stock inicial.')]);
        }

        return ['sucursal_id' => $sucursal->id, 'clave' => $datos['clave'], 'lote' => $datos['lote'] ?? null, 'vencimiento' => $datos['vencimiento'] ?? null];
    }

    /**
     * Dentro de la transacción del alta, después de guardar los productos. Las líneas son solo las que llevan cantidad.
     *
     * @param  array{sucursal_id: int, clave: string, lote: mixed, vencimiento: mixed}  $preparado
     * @param  list<array{producto_id: int, cantidad: string}>  $lineas
     */
    public function aplicar(User $actor, array $preparado, array $lineas, string $nota): OperacionInventario
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('StockInicial::aplicar() requiere la transacción del alta del producto.');
        }

        $empresaId = (int) $actor->empresa_id;

        try {
            $preparadas = $this->libro->prepararLista($empresaId, $lineas);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['stock_inicial' => $e->errors()['lineas'] ?? $e->getMessage()]);
        }

        $cambios = [];
        $errores = [];

        foreach ($preparadas as $linea) {
            $cambio = [
                'sucursal_id' => $preparado['sucursal_id'], 'producto_id' => $linea['producto_id'],
                'cantidad' => $linea['cantidad'], 'nombre' => $linea['nombre'],
            ];

            if ($linea['controla_vencimiento']) {
                $lote = DatosLote::leer($linea['nombre'], $preparado['lote'], $preparado['vencimiento'], $errores);

                if ($lote === null) {
                    continue;
                }

                $cambio['lote'] = $lote;
            }

            $cambios[] = $cambio;
        }

        if ($errores !== []) {
            throw ValidationException::withMessages(['stock_lote' => array_values(array_unique($errores, SORT_REGULAR))]);
        }

        // Productos recién creados en esta misma transacción: nadie más puede tener sus saldos, así que crearlos aquí
        // no se cruza con otros bloqueos (la importación de productos hace lo mismo).
        $this->libro->asegurarNiveles($empresaId, $cambios);

        return $this->libro->aplicar($actor, [
            'tipo' => TipoOperacionInventario::Entrada,
            'motivo' => 'inventario_inicial',
            'sucursal_id' => $preparado['sucursal_id'],
            'sucursal_destino_id' => null,
            'nota' => mb_substr($nota, 0, 250),
            'clave' => $preparado['clave'],
        ], $cambios);
    }

    /**
     * Producto que se creó con esa clave (el primero de la operación), o null si la clave aún no se usó.
     */
    public function productoDe(int $empresaId, string $clave): ?int
    {
        $operacion = $this->libro->existente($empresaId, $clave);

        if ($operacion === null) {
            return null;
        }

        $id = DB::table('movimientos_stock')->where('empresa_id', $empresaId)->where('operacion_id', $operacion->id)
            ->orderBy('id')->value('producto_id');

        return $id === null ? null : (int) $id;
    }
}
