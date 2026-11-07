<?php

namespace App\Domain\Creditos\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Caja\CajaOperacion;
use App\Domain\Caja\LibroCaja;
use App\Models\Cobro;
use App\Models\CobroAplicacion;
use App\Models\CuentaCobrar;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaCuota;
use App\Support\Decimal;
use App\Support\MotivoAnulacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Anulación de un cobro por el administrador (decisión del propietario, 31/10/2026). En una
 * transacción: bloquea los saldos que cubrió (ventas, cuotas y cuentas, en el orden de
 * RegistrarCobro) y el cobro, devuelve a cada documento lo aplicado y registra la salida del
 * mismo dinero por medio con la fecha y hora actuales (origen «anulacion_cobro»). El cobro
 * original sigue en su día y en su historial, marcado como anulado: los arqueos cerrados no
 * cambian. Se anula entero aunque haya cubierto varios documentos. Anular dos veces no hace nada.
 *
 * No se anula si una venta cubierta tuvo después una devolución (su reembolso se calculó con
 * ese cobro) o si su plan de cuotas se definió después del cobro (no sabríamos a qué cuota volver).
 */
final class AnularCobro
{
    public function __construct(
        private readonly CajaOperacion $cajas,
        private readonly LibroCaja $caja,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{motivo: string|null, externo?: bool, turno_id?: int|null}  $datos
     */
    public function ejecutar(User $actor, Cobro $cobro, array $datos): Cobro
    {
        Gate::forUser($actor)->authorize('anular', $cobro);
        $motivo = MotivoAnulacion::validar($datos['motivo'] ?? null);

        return DB::transaction(function () use ($actor, $cobro, $datos, $motivo): Cobro {
            // Las aplicaciones no cambian nunca: se leen sin bloqueo para saber qué saldos bloquear.
            $aplicaciones = CobroAplicacion::query()->where('cobro_id', $cobro->id)->orderBy('id')->get();
            $ventaIds = $aplicaciones->pluck('venta_id')->filter()->unique()->sort()->values()->all();
            $ventas = Venta::query()->whereKey($ventaIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $cuotas = $ventaIds === [] ? collect() : VentaCuota::query()->whereIn('venta_id', $ventaIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $cuentas = CuentaCobrar::query()->whereKey($aplicaciones->pluck('cuenta_cobrar_id')->filter()->unique()->all())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $bloqueado = Cobro::query()->whereKey($cobro->id)->lockForUpdate()->firstOrFail();

            if ($bloqueado->estaAnulado()) {
                return $bloqueado;
            }

            $devolucion = DB::table('devoluciones')->whereIn('venta_id', $ventaIds)->where('fecha', '>=', $bloqueado->fecha_cobro)
                ->orderBy('venta_id')->value('venta_id');

            if ($devolucion !== null) {
                throw ValidationException::withMessages(['motivo' => __(':venta tuvo una devolución después de este cobro; anularlo descuadraría su saldo y su reembolso.',
                    ['venta' => $ventas[$devolucion]->codigo()])]);
            }

            foreach ($aplicaciones as $aplicacion) {
                if ($aplicacion->venta_id !== null && $aplicacion->venta_cuota_id === null && $cuotas->contains('venta_id', $aplicacion->venta_id)) {
                    throw ValidationException::withMessages(['motivo' => __('El plan de cuotas de :venta se definió después de este cobro; no puede anularse.',
                        ['venta' => $ventas[$aplicacion->venta_id]->codigo()])]);
                }
            }

            $detalle = [];

            foreach ($aplicaciones as $aplicacion) {
                if ($aplicacion->venta_cuota_id !== null) {
                    $cuota = $cuotas[$aplicacion->venta_cuota_id];
                    $cuota->saldo = Decimal::sumar($cuota->saldo, $aplicacion->monto, Decimal::ESCALA_DINERO);
                    $cuota->save();
                }

                if ($aplicacion->venta_id !== null) {
                    $venta = $ventas[$aplicacion->venta_id];
                    $venta->saldo_pendiente = Decimal::sumar($venta->saldo_pendiente, $aplicacion->monto, Decimal::ESCALA_DINERO);
                    $detalle[] = ['documento' => $venta->codigo(), 'importe' => $aplicacion->monto];
                } else {
                    $cuenta = $cuentas[$aplicacion->cuenta_cobrar_id];
                    $cuenta->saldo_pendiente = Decimal::sumar($cuenta->saldo_pendiente, $aplicacion->monto, Decimal::ESCALA_DINERO);
                    $cuenta->save();
                    $detalle[] = ['documento' => $cuenta->codigo(), 'importe' => $aplicacion->monto];
                }
            }

            foreach ($ventas as $venta) {
                if ($venta->isDirty('saldo_pendiente')) {
                    $venta->save();
                }
            }

            $caja = $this->cajas->resolver($actor, $bloqueado->sucursal_id, $bloqueado->monto_efectivo, (bool) ($datos['externo'] ?? false),
                'externo', isset($datos['turno_id']) ? (int) $datos['turno_id'] : null);
            $ahora = Carbon::now();
            $this->caja->registrar($actor, $bloqueado->sucursal_id, 'egreso',
                ['efectivo' => $bloqueado->monto_efectivo, 'electronico' => $bloqueado->monto_electronico], 'anulacion_cobro', $bloqueado->id, $ahora,
                $caja['caja_id'], $caja['turno_id']);

            $bloqueado->anulado_at = $ahora;
            $bloqueado->anulado_por = $actor->id;
            $bloqueado->motivo_anulacion = $motivo;
            $bloqueado->save();

            $this->auditor->registrar('cobro.anulado', $bloqueado, $actor, $bloqueado->empresa_id, despues: [
                'cobro' => $bloqueado->codigo(), 'monto' => $bloqueado->monto, 'efectivo' => $bloqueado->monto_efectivo,
                'electronico' => $bloqueado->monto_electronico, 'motivo' => $motivo, 'externo' => $caja['externo'],
                'turno_caja_id' => $caja['turno_id'], 'saldos_devueltos' => $detalle,
            ]);

            return $bloqueado;
        }, attempts: 3);
    }
}
