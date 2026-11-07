<?php

namespace App\Domain\CuentasPagar\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Caja\CajaOperacion;
use App\Domain\Caja\LibroCaja;
use App\Models\CuentaPagar;
use App\Models\PagoCuentaPagar;
use App\Models\User;
use App\Support\Decimal;
use App\Support\MotivoAnulacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Anulación de un pago de cuenta por pagar por el administrador (decisión del propietario,
 * 31/10/2026). En una transacción: bloquea la cuenta (como RegistrarPagoCuenta) y el pago,
 * devuelve el importe al saldo y registra la entrada del mismo dinero por medio con la fecha y
 * hora actuales (origen «anulacion_pago_cxp»). El pago sigue en su día y en el historial,
 * marcado como anulado; los arqueos cerrados no cambian. Anular dos veces no hace nada.
 */
final class AnularPagoCuenta
{
    public function __construct(
        private readonly CajaOperacion $cajas,
        private readonly LibroCaja $caja,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{motivo: string|null, externo?: bool, turno_id?: int|null}  $datos
     */
    public function ejecutar(User $actor, PagoCuentaPagar $pago, array $datos): PagoCuentaPagar
    {
        Gate::forUser($actor)->authorize('anular', CuentaPagar::query()->findOrFail($pago->cuenta_pagar_id));
        $motivo = MotivoAnulacion::validar($datos['motivo'] ?? null);

        return DB::transaction(function () use ($actor, $pago, $datos, $motivo): PagoCuentaPagar {
            $cuenta = CuentaPagar::query()->whereKey($pago->cuenta_pagar_id)->lockForUpdate()->firstOrFail();
            $bloqueado = PagoCuentaPagar::query()->whereKey($pago->id)->lockForUpdate()->firstOrFail();

            if ($bloqueado->estaAnulado()) {
                return $bloqueado;
            }

            $antes = $cuenta->saldo_pendiente;
            $cuenta->saldo_pendiente = Decimal::sumar($cuenta->saldo_pendiente, $bloqueado->monto, Decimal::ESCALA_DINERO);
            $cuenta->save();

            $caja = $this->cajas->resolver($actor, $bloqueado->sucursal_id, $bloqueado->monto_efectivo, (bool) ($datos['externo'] ?? false),
                'externo', isset($datos['turno_id']) ? (int) $datos['turno_id'] : null);
            $ahora = Carbon::now();
            $this->caja->registrar($actor, $bloqueado->sucursal_id, 'ingreso',
                ['efectivo' => $bloqueado->monto_efectivo, 'electronico' => $bloqueado->monto_electronico], 'anulacion_pago_cxp', $bloqueado->id, $ahora,
                $caja['caja_id'], $caja['turno_id']);

            $bloqueado->anulado_at = $ahora;
            $bloqueado->anulado_por = $actor->id;
            $bloqueado->motivo_anulacion = $motivo;
            $bloqueado->save();

            $this->auditor->registrar('cuenta_pagar.pago_anulado', $cuenta, $actor, $cuenta->empresa_id, ['saldo_pendiente' => $antes], [
                'cuenta' => $cuenta->codigo(), 'pago_id' => $bloqueado->id, 'monto' => $bloqueado->monto, 'efectivo' => $bloqueado->monto_efectivo,
                'electronico' => $bloqueado->monto_electronico, 'saldo_pendiente' => $cuenta->saldo_pendiente, 'motivo' => $motivo,
                'externo' => $caja['externo'], 'turno_caja_id' => $caja['turno_id'],
            ]);

            return $bloqueado;
        }, attempts: 3);
    }
}
