<?php

namespace App\Domain\Suscripciones\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Suscripciones\CalculadoraProrrateo;
use App\Domain\Suscripciones\CalendarioSuscripcion;
use App\Models\CambioPlan;
use App\Models\Empresa;
use App\Models\Plan;
use App\Models\Suscripcion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Actualización de plan por parte de la empresa (§ 8.4, 26/10/2026):
 * - Upgrade: paga únicamente la diferencia proporcional por los días restantes. La fecha
 *   de vencimiento se mantiene y en la próxima renovación pagará el precio completo.
 * - Downgrade: conserva los beneficios del plan actual hasta su vencimiento. En la siguiente
 *   renovación se aplica el nuevo plan con su precio reducido.
 */
final class CambiarPlanSuscripcion
{
    public function __construct(private readonly Auditor $auditor) {}

    public function ejecutar(User $actor, int $nuevoPlanId): CambioPlan
    {
        Gate::forUser($actor)->authorize('cambiarPlan', Suscripcion::class);

        return DB::transaction(function () use ($actor, $nuevoPlanId): CambioPlan {
            $empresaId = (int) $actor->empresa_id;

            $empresa = Empresa::query()->whereKey($empresaId)->lockForUpdate()->firstOrFail();
            $suscripcion = Suscripcion::query()->where('empresa_id', $empresaId)->lockForUpdate()->firstOrFail();

            $planNuevo = Plan::query()->whereKey($nuevoPlanId)->where('activo', true)->lockForUpdate()->firstOrFail();
            $planActual = $suscripcion->plan;

            if ($planActual !== null && $planActual->id === $planNuevo->id && $suscripcion->plan_siguiente_id === null) {
                throw ValidationException::withMessages(['plan_id' => __('Ya te encuentras en este plan.')]);
            }

            $hoy = CalendarioSuscripcion::hoy();
            $calculo = CalculadoraProrrateo::calcular($suscripcion, $planNuevo, $hoy);

            if ($calculo['tipo'] === 'upgrade') {
                $anteriorId = $suscripcion->plan_id ?? $planNuevo->id;
                $suscripcion->plan_id = $planNuevo->id;
                $suscripcion->plan_siguiente_id = null;
                $suscripcion->precio_mensual = $planNuevo->precio_mensual;
                $suscripcion->save();

                if ($planNuevo->siat_habilitado && ! $empresa->siat_habilitado) {
                    $empresa->siat_habilitado = true;
                    $empresa->save();
                }

                $cambio = CambioPlan::create([
                    'empresa_id' => $empresaId,
                    'suscripcion_id' => $suscripcion->id,
                    'plan_anterior_id' => $anteriorId,
                    'plan_nuevo_id' => $planNuevo->id,
                    'tipo' => 'upgrade',
                    'dias_restantes' => $calculo['dias_restantes'],
                    'monto_prorrateado' => $calculo['monto_prorrateado'],
                    'precio_nuevo_completo' => $planNuevo->precio_mensual,
                    'vencimiento_conservado' => $suscripcion->vencimiento,
                    'usuario_id' => $actor->id,
                ]);

                $this->auditor->registrar('suscripcion.plan_subido', $cambio, $actor, $empresaId, despues: [
                    'plan_nuevo' => $planNuevo->titulo,
                    'monto_prorrateado' => (string) $calculo['monto_prorrateado'],
                    'precio_proxima_renovacion' => (string) $planNuevo->precio_mensual,
                    'vencimiento' => $suscripcion->vencimiento->format('Y-m-d'),
                ]);

                return $cambio;
            } else {
                // Downgrade: conserva beneficios y programa el cambio para la siguiente renovación
                $suscripcion->plan_siguiente_id = $planNuevo->id;
                $suscripcion->save();

                $cambio = CambioPlan::create([
                    'empresa_id' => $empresaId,
                    'suscripcion_id' => $suscripcion->id,
                    'plan_anterior_id' => $suscripcion->plan_id ?? $planNuevo->id,
                    'plan_nuevo_id' => $planNuevo->id,
                    'tipo' => 'downgrade',
                    'dias_restantes' => $calculo['dias_restantes'],
                    'monto_prorrateado' => '0.00',
                    'precio_nuevo_completo' => $planNuevo->precio_mensual,
                    'vencimiento_conservado' => $suscripcion->vencimiento,
                    'usuario_id' => $actor->id,
                ]);

                $this->auditor->registrar('suscripcion.plan_bajado_programado', $cambio, $actor, $empresaId, despues: [
                    'plan_siguiente' => $planNuevo->titulo,
                    'precio_reducido' => (string) $planNuevo->precio_mensual,
                    'aplica_en_vencimiento' => $suscripcion->vencimiento->format('Y-m-d'),
                ]);

                return $cambio;
            }
        });
    }

    public function cancelarCambioProgramado(User $actor): void
    {
        Gate::forUser($actor)->authorize('cambiarPlan', Suscripcion::class);

        DB::transaction(function () use ($actor): void {
            $empresaId = (int) $actor->empresa_id;
            $suscripcion = Suscripcion::query()->where('empresa_id', $empresaId)->lockForUpdate()->firstOrFail();

            if ($suscripcion->plan_siguiente_id === null) {
                return;
            }

            $canceladoId = $suscripcion->plan_siguiente_id;
            $suscripcion->plan_siguiente_id = null;
            $suscripcion->save();

            $this->auditor->registrar('suscripcion.cambio_programado_cancelado', $suscripcion, $actor, $empresaId, despues: [
                'plan_cancelado_id' => $canceladoId,
            ]);
        });
    }
}
