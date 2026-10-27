<?php

namespace App\Domain\Creditos\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Tenancy\ResolverSucursal;
use App\Domain\Ventas\RegistradorCobros;
use App\Domain\Ventas\ValidadorPago;
use App\Models\PagoVenta;
use App\Models\Scopes\EmpresaScope;
use App\Models\User;
use App\Models\Venta;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Abono posterior a una venta a crédito (especificación § 5.3 y V3 § 6). En una transacción:
 * bloquea la venta, valida que el abono sea mayor que 0 y no supere el saldo, registra un
 * único pago con su desglose y su entrada en caja con la fecha y hora reales de recepción,
 * y descuenta el saldo. Decisiones del propietario (23/10/2026): la fecha es siempre la actual
 * y un vendedor cobra cualquier crédito de su empresa en su propia sucursal; el vendedor
 * original de la venta no cambia. Repetir la misma clave no vuelve a cobrar.
 */
final class RegistrarAbono
{
    public function __construct(
        private readonly ResolverSucursal $resolverSucursal,
        private readonly RegistradorCobros $cobros,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{sucursal_id: int|null, metodo: string|null, monto: string|null, efectivo: string|null, electronico: string|null, nota: string|null, clave: string}  $datos
     */
    public function ejecutar(User $actor, Venta $venta, array $datos): PagoVenta
    {
        Gate::forUser($actor)->authorize('cobrar', $venta);

        if (! Str::isUuid($datos['clave'])) {
            throw ValidationException::withMessages(['monto' => __('El abono no tiene una clave válida. Recarga la página.')]);
        }

        if (($previo = $this->existente((int) $actor->empresa_id, $datos['clave'])) !== null) {
            return $previo;
        }

        $sucursal = $this->resolverSucursal->paraOperacion($actor, $datos['sucursal_id']);
        $nota = trim((string) $datos['nota']);

        try {
            return DB::transaction(function () use ($actor, $venta, $datos, $sucursal, $nota): PagoVenta {
                $bloqueada = Venta::query()->whereKey($venta->id)->lockForUpdate()->firstOrFail();

                if (Decimal::comparar($bloqueada->saldo_pendiente, '0', 2) <= 0) {
                    throw ValidationException::withMessages(['monto' => __('Este crédito ya está pagado.')]);
                }

                $abono = ValidadorPago::abono($bloqueada->saldo_pendiente, $datos['metodo'], $datos['monto'],
                    $datos['efectivo'], $datos['electronico']);
                $antes = $bloqueada->saldo_pendiente;

                $pago = $this->cobros->registrar($actor, $bloqueada, $sucursal->id, 'abono', $abono['metodo'],
                    $abono['efectivo'], $abono['electronico'], $datos['clave'], $nota === '' ? null : mb_substr($nota, 0, 150));

                $bloqueada->saldo_pendiente = Decimal::restar($antes, $abono['monto'], Decimal::ESCALA_DINERO);
                $bloqueada->save();

                $this->auditor->registrar('venta.abono', $bloqueada, $actor, $bloqueada->empresa_id,
                    ['saldo_pendiente' => $antes],
                    ['saldo_pendiente' => $bloqueada->saldo_pendiente, 'abono' => $abono['monto'], 'metodo' => $abono['metodo']->value,
                        'sucursal_id' => $sucursal->id]);

                return $pago;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            return $this->existente((int) $actor->empresa_id, $datos['clave']) ?? throw $e;
        }
    }

    /**
     * Lee la base en cada llamada: tras un choque de clave el pago ya existe.
     *
     * @phpstan-impure
     */
    private function existente(int $empresaId, string $clave): ?PagoVenta
    {
        return PagoVenta::withoutGlobalScope(EmpresaScope::class)
            ->where('empresa_id', $empresaId)->where('clave_idempotencia', $clave)->first();
    }
}
