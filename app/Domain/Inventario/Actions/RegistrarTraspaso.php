<?php

namespace App\Domain\Inventario\Actions;

use App\Domain\Inventario\LibroStock;
use App\Domain\Tenancy\ResolverSucursal;
use App\Enums\TipoOperacionInventario;
use App\Models\OperacionInventario;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Traspaso entre dos sucursales activas de la misma empresa: resta en origen y suma en
 * destino en una sola transacción, con historial pareado bajo la misma operación.
 * No genera venta, ingreso ni egreso.
 */
final class RegistrarTraspaso
{
    public function __construct(
        private readonly LibroStock $libro,
        private readonly ResolverSucursal $resolverSucursal,
    ) {}

    /**
     * @param  array{sucursal_id: int|null, sucursal_destino_id: int|null, nota?: string|null, lineas: array<int|string, mixed>, clave: string}  $datos
     */
    public function ejecutar(User $actor, array $datos): OperacionInventario
    {
        Gate::forUser($actor)->authorize('create', OperacionInventario::class);

        $origen = $this->resolverSucursal->paraOperacion($actor, $datos['sucursal_id']);

        try {
            $destino = $this->resolverSucursal->paraOperacion($actor, $datos['sucursal_destino_id']);
        } catch (ValidationException) {
            throw ValidationException::withMessages(['sucursal_destino_id' => __('Selecciona una sucursal de destino activa de tu empresa.')]);
        }

        if ($origen->id === $destino->id) {
            throw ValidationException::withMessages(['sucursal_destino_id' => __('El destino debe ser distinto del origen.')]);
        }

        $nota = trim((string) ($datos['nota'] ?? ''));

        if (mb_strlen($nota) > 250) {
            throw ValidationException::withMessages(['nota' => __('La nota admite hasta 250 caracteres.')]);
        }

        $cambios = [];

        foreach ($this->libro->prepararLineas((int) $actor->empresa_id, $datos['lineas']) as $productoId => $linea) {
            $cambios[] = ['sucursal_id' => $origen->id, 'producto_id' => $productoId, 'cantidad' => '-'.$linea['cantidad'], 'nombre' => $linea['nombre']];
            // Lotes y vencimientos (§ 5.3): el destino recibe los mismos lotes que el reparto FEFO sacó del origen.
            $cambios[] = ['sucursal_id' => $destino->id, 'producto_id' => $productoId, 'cantidad' => $linea['cantidad'], 'nombre' => $linea['nombre'], 'replica_de' => $origen->id];
        }

        return $this->libro->registrar($actor, [
            'tipo' => TipoOperacionInventario::Traspaso,
            'motivo' => null,
            'sucursal_id' => $origen->id,
            'sucursal_destino_id' => $destino->id,
            'nota' => $nota === '' ? null : $nota,
            'clave' => $datos['clave'],
        ], $cambios);
    }
}
