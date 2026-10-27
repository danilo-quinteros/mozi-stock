<?php

namespace App\Domain\Inventario\Actions;

use App\Domain\Inventario\LibroStock;
use App\Domain\Tenancy\ResolverSucursal;
use App\Enums\TipoOperacionInventario;
use App\Models\OperacionInventario;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Entrada, salida o corrección manual de existencias en una sucursal (V3 § 3).
 * No es un cobro ni un egreso, y no sustituye a compras ni ventas.
 */
final class RegistrarAjuste
{
    public function __construct(
        private readonly LibroStock $libro,
        private readonly ResolverSucursal $resolverSucursal,
    ) {}

    /**
     * @param  array{tipo: string, motivo?: string|null, direccion?: string|null, sucursal_id: int|null, nota: string, lineas: array<int|string, mixed>, clave: string}  $datos
     */
    public function ejecutar(User $actor, array $datos): OperacionInventario
    {
        Gate::forUser($actor)->authorize('create', OperacionInventario::class);

        $tipo = TipoOperacionInventario::tryFrom($datos['tipo']);

        if ($tipo === null || $tipo === TipoOperacionInventario::Traspaso) {
            throw ValidationException::withMessages(['tipo' => __('Selecciona Entrada, Salida o Ajuste.')]);
        }

        $motivo = null;
        $signo = '1';

        if ($tipo === TipoOperacionInventario::Ajuste) {
            $signo = match ($datos['direccion'] ?? null) {
                'sumar' => '1',
                'restar' => '-1',
                default => throw ValidationException::withMessages(['direccion' => __('Indica si el ajuste suma o resta existencias.')]),
            };
        } else {
            $motivo = $datos['motivo'] ?? null;

            if (! array_key_exists((string) $motivo, $tipo->motivos())) {
                throw ValidationException::withMessages(['motivo' => __('Selecciona un motivo válido para la :tipo.', ['tipo' => mb_strtolower($tipo->etiqueta())])]);
            }

            $signo = $tipo === TipoOperacionInventario::Salida ? '-1' : '1';
        }

        $nota = trim($datos['nota']);

        if ($nota === '' || mb_strlen($nota) > 250) {
            throw ValidationException::withMessages(['nota' => __('Escribe una nota de hasta 250 caracteres que explique el movimiento.')]);
        }

        $sucursal = $this->resolverSucursal->paraOperacion($actor, $datos['sucursal_id']);
        $cambios = [];

        foreach ($this->libro->prepararLineas((int) $actor->empresa_id, $datos['lineas']) as $productoId => $linea) {
            $cambios[] = [
                'sucursal_id' => $sucursal->id,
                'producto_id' => $productoId,
                'cantidad' => Decimal::multiplicar($linea['cantidad'], $signo),
                'nombre' => $linea['nombre'],
            ];
        }

        return $this->libro->registrar($actor, [
            'tipo' => $tipo,
            'motivo' => $motivo,
            'sucursal_id' => $sucursal->id,
            'sucursal_destino_id' => null,
            'nota' => $nota,
            'clave' => $datos['clave'],
        ], $cambios);
    }
}
