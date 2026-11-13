<?php

namespace App\Domain\Inventario\Actions;

use App\Domain\Inventario\DatosLote;
use App\Domain\Inventario\LibroLotes;
use App\Domain\Inventario\LibroStock;
use App\Domain\Tenancy\ResolverSucursal;
use App\Enums\TipoOperacionInventario;
use App\Models\OperacionInventario;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Regularización del stock «Sin lote» (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 9.3): en una sucursal, resta unidades
 * del lote de regularización de un producto y las suma a uno o más lotes reales con su número y vencimiento. Es una
 * sola operación de tipo Ajuste (motivo «Regularización de lote», serie LOT) con nota obligatoria; el total de la
 * sucursal no cambia.
 *
 * Pasa por LibroStock y LibroLotes como cualquier ajuste: mismos bloqueos, misma idempotencia por clave, mismas reglas
 * del lote (un número = un vencimiento) y nunca más de lo que el lote de regularización tiene en la sucursal.
 */
final class RegularizarLote
{
    public function __construct(
        private readonly LibroStock $libro,
        private readonly LibroLotes $lotes,
        private readonly ResolverSucursal $resolverSucursal,
    ) {}

    /**
     * @param  array{sucursal_id: int|null, producto_id: mixed, nota: string, lineas: list<array{lote?: mixed, vencimiento?: mixed, cantidad?: mixed}>, clave: string}  $datos
     */
    public function ejecutar(User $actor, array $datos): OperacionInventario
    {
        Gate::forUser($actor)->authorize('create', OperacionInventario::class);
        $empresaId = (int) $actor->empresa_id;

        if (! $this->lotes->activo($empresaId)) {
            throw ValidationException::withMessages(['lineas' => __('La empresa no tiene activa la función «Lotes y vencimientos».')]);
        }

        $nota = trim($datos['nota']);

        if ($nota === '' || mb_strlen($nota) > 250) {
            throw ValidationException::withMessages(['nota' => __('Escribe una nota de hasta 250 caracteres que explique el movimiento.')]);
        }

        $sucursal = $this->resolverSucursal->paraOperacion($actor, $datos['sucursal_id']);
        $lineas = $datos['lineas'];
        $preparadas = $this->libro->prepararLista($empresaId, array_map(fn (array $l): array => [
            'producto_id' => $datos['producto_id'], 'cantidad' => $l['cantidad'] ?? '',
        ], $lineas));
        $primera = reset($preparadas);

        if ($primera === false || ! $primera['controla_vencimiento']) {
            throw ValidationException::withMessages(['lineas' => __('El producto no controla lote y vencimiento.')]);
        }

        $productoId = $primera['producto_id'];
        $cambios = [];
        $errores = [];
        $total = '0';

        foreach ($preparadas as $indice => $linea) {
            $lote = DatosLote::leer($linea['nombre'], $lineas[$indice]['lote'] ?? null, $lineas[$indice]['vencimiento'] ?? null, $errores);

            if ($lote !== null) {
                $cambios[] = ['sucursal_id' => $sucursal->id, 'producto_id' => $productoId, 'cantidad' => $linea['cantidad'], 'nombre' => $linea['nombre'], 'lote' => $lote];
                $total = Decimal::sumar($total, $linea['cantidad']);
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages(['lineas' => array_values(array_unique($errores, SORT_REGULAR))]);
        }

        // La salida del lote de regularización va primero: LibroLotes valida que tenga ese stock en la sucursal.
        array_unshift($cambios, [
            'sucursal_id' => $sucursal->id, 'producto_id' => $productoId, 'cantidad' => Decimal::restar('0', $total),
            'nombre' => $primera['nombre'], 'lote_id' => $this->lotes->loteRegularizacion($empresaId, $productoId),
        ]);

        return $this->libro->registrar($actor, [
            'tipo' => TipoOperacionInventario::Ajuste,
            'motivo' => 'regularizacion_lote',
            'sucursal_id' => $sucursal->id,
            'sucursal_destino_id' => null,
            'nota' => $nota,
            'clave' => $datos['clave'],
            'serie' => TipoOperacionInventario::SERIE_LOTES,
        ], $cambios);
    }
}
