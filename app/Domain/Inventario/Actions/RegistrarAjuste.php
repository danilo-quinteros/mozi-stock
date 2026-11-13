<?php

namespace App\Domain\Inventario\Actions;

use App\Domain\Inventario\DatosLote;
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
 *
 * Lotes y vencimientos (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 5.1 y § 5.4), en productos con vencimiento de una
 * empresa con la capacidad:
 * - una entrada o un ajuste que suma exige número de lote y vencimiento en cada línea; el mismo producto puede ir
 *   en varias líneas, una por lote;
 * - una salida o un ajuste que resta admite un lote elegido por línea (`lote_id`, también vencido: así se dan de
 *   baja) o el reparto automático, que toma primero los vencidos y luego por FEFO. LibroLotes valida que el lote sea
 *   de la empresa, del producto y tenga stock en la sucursal.
 * Los demás productos se agrupan por producto, como siempre.
 */
final class RegistrarAjuste
{
    public function __construct(
        private readonly LibroStock $libro,
        private readonly ResolverSucursal $resolverSucursal,
    ) {}

    /**
     * Las líneas pueden venir como lista [{producto_id, cantidad, lote?, vencimiento?, lote_id?}, …] o, como antes,
     * producto_id => cantidad.
     *
     * @param  array{tipo: string, motivo?: string|null, direccion?: string|null, sucursal_id: int|null, nota: string, lineas: array<int|string, mixed>, clave: string}  $datos
     */
    public function ejecutar(User $actor, array $datos): OperacionInventario
    {
        Gate::forUser($actor)->authorize('create', OperacionInventario::class);

        $tipo = TipoOperacionInventario::tryFrom($datos['tipo']);

        if ($tipo === null || $tipo === TipoOperacionInventario::Traspaso || ! in_array($tipo, TipoOperacionInventario::manuales(), true)) {
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

        return $this->libro->registrar($actor, [
            'tipo' => $tipo,
            'motivo' => $motivo,
            'sucursal_id' => $sucursal->id,
            'sucursal_destino_id' => null,
            'nota' => $nota,
            'clave' => $datos['clave'],
        ], $this->cambios((int) $actor->empresa_id, $sucursal->id, $datos['lineas'], $signo));
    }

    /**
     * @param  array<int|string, mixed>  $lineas
     * @return list<array{sucursal_id: int, producto_id: int, cantidad: string, nombre: string, lote?: array{numero: string, vencimiento: string}, lote_id?: int}>
     */
    private function cambios(int $empresaId, int $sucursalId, array $lineas, string $signo): array
    {
        $lista = [];

        foreach ($lineas as $clave => $linea) {
            $lista[] = is_array($linea) ? $linea : ['producto_id' => $clave, 'cantidad' => $linea];
        }

        $preparadas = $this->libro->prepararLista($empresaId, array_map(fn (array $l): array => [
            'producto_id' => $l['producto_id'] ?? null, 'cantidad' => $l['cantidad'] ?? '',
        ], $lista));
        $ingreso = $signo === '1';
        $cambios = [];
        $errores = [];

        foreach ($preparadas as $indice => $linea) {
            $cambio = [
                'sucursal_id' => $sucursalId,
                'producto_id' => $linea['producto_id'],
                'cantidad' => Decimal::multiplicar($linea['cantidad'], $signo),
                'nombre' => $linea['nombre'],
            ];

            if (! $linea['controla_vencimiento']) {
                // Sin lote: una línea por producto, como siempre.
                $clave = 'p'.$linea['producto_id'];
                $cambios[$clave] = isset($cambios[$clave])
                    ? ['cantidad' => Decimal::sumar($cambios[$clave]['cantidad'], $cambio['cantidad'])] + $cambios[$clave]
                    : $cambio;

                continue;
            }

            if ($ingreso) {
                $lote = DatosLote::leer($linea['nombre'], $lista[$indice]['lote'] ?? null, $lista[$indice]['vencimiento'] ?? null, $errores);

                if ($lote !== null) {
                    $cambios[] = $cambio + ['lote' => $lote];
                }

                continue;
            }

            $elegido = $lista[$indice]['lote_id'] ?? null;

            if ($elegido === null || $elegido === '' || $elegido === 'auto') {
                $cambios[] = $cambio;
            } elseif (is_numeric($elegido) && (int) $elegido > 0) {
                $cambios[] = $cambio + ['lote_id' => (int) $elegido];
            } else {
                $errores[] = __(':producto: el lote indicado no existe.', ['producto' => $linea['nombre']]);
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages(['lineas' => array_values(array_unique($errores, SORT_REGULAR))]);
        }

        return array_values($cambios);
    }
}
