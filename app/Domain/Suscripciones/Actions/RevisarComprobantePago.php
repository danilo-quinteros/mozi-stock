<?php

namespace App\Domain\Suscripciones\Actions;

use App\Domain\Auditoria\Auditor;
use App\Enums\EstadoPagoSaas;
use App\Models\Empresa;
use App\Models\PagoSaas;
use App\Models\Plan;
use App\Models\Scopes\EmpresaScope;
use App\Models\Suscripcion;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Revisión manual del superadministrador (§ 3.12, T25). Aprobar exige confirmar que se
 * recibió exactamente el importe (sin pagos parciales, § 8.4) y avanza el vencimiento los
 * meses pagados conservando el día. Rechazar exige un motivo y no renueva. Repetir la misma
 * decisión no hace nada. No toca el acceso de la empresa (T26) ni su caja (T27).
 *
 * Orden de bloqueo: suscripción → comprobante (el mismo que usa el envío).
 */
final class RevisarComprobantePago
{
    public function __construct(private readonly Auditor $auditor) {}

    public function aprobar(User $actor, int $pagoId, bool $importeVerificado): PagoSaas
    {
        Gate::forUser($actor)->authorize('revisar', PagoSaas::class);

        if (! $importeVerificado) {
            throw ValidationException::withMessages(['verificado' => __('Confirma que verificaste el importe exacto recibido por MoziStudios.')]);
        }

        return DB::transaction(function () use ($actor, $pagoId): PagoSaas {
            [$suscripcion, $pago] = $this->bloquear($pagoId);

            if ($pago->estado === EstadoPagoSaas::Aprobado) {
                return $pago;
            }

            if ($pago->estado === EstadoPagoSaas::Rechazado) {
                throw ValidationException::withMessages(['verificado' => __('Este comprobante ya fue rechazado; la empresa debe enviar uno nuevo.')]);
            }

            if ($pago->esExtras()) {
                return $this->aprobarExtras($actor, $suscripcion, $pago);
            }

            $anterior = $suscripcion->vencimiento;
            $suscripcion->vencimiento = $suscripcion->vencimientoTras((int) $pago->meses);

            if ($suscripcion->plan_siguiente_id !== null) {
                $planSiguiente = Plan::query()->find($suscripcion->plan_siguiente_id);
                if ($planSiguiente !== null) {
                    $suscripcion->plan_id = $planSiguiente->id;
                    $suscripcion->precio_mensual = $planSiguiente->precio_mensual;
                    $suscripcion->plan_siguiente_id = null;

                    $empresa = Empresa::query()->find($suscripcion->empresa_id);
                    if ($empresa !== null) {
                        $empresa->siat_habilitado = $planSiguiente->siat_habilitado;
                        $empresa->save();
                    }
                }
            }

            $suscripcion->save();

            $pago->estado = EstadoPagoSaas::Aprobado;
            $pago->revisado_por = $actor->id;
            $pago->revisado_en = Carbon::now();
            $pago->vencimiento_anterior = $anterior;
            $pago->vencimiento_nuevo = $suscripcion->vencimiento;
            $pago->save();

            $this->auditor->registrar('suscripcion.pago_aprobado', $pago, $actor, $pago->empresa_id,
                ['vencimiento' => $anterior->format('Y-m-d')],
                ['vencimiento' => $suscripcion->vencimiento->format('Y-m-d'), 'comprobante' => $pago->codigo(),
                    'meses' => $pago->meses, 'importe' => (string) $pago->importe]);

            return $pago;
        }, attempts: 3);
    }

    public function rechazar(User $actor, int $pagoId, ?string $motivo): PagoSaas
    {
        Gate::forUser($actor)->authorize('revisar', PagoSaas::class);
        $motivo = trim((string) $motivo);

        if ($motivo === '' || mb_strlen($motivo) > 300) {
            throw ValidationException::withMessages(['motivo' => __('Explica a la empresa por qué se rechaza (hasta 300 caracteres).')]);
        }

        return DB::transaction(function () use ($actor, $pagoId, $motivo): PagoSaas {
            [, $pago] = $this->bloquear($pagoId);

            if ($pago->estado === EstadoPagoSaas::Rechazado) {
                return $pago;
            }

            if ($pago->estado === EstadoPagoSaas::Aprobado) {
                throw ValidationException::withMessages(['motivo' => __('Este comprobante ya fue aprobado y renovó la suscripción.')]);
            }

            $pago->estado = EstadoPagoSaas::Rechazado;
            $pago->revisado_por = $actor->id;
            $pago->revisado_en = Carbon::now();
            $pago->motivo_rechazo = $motivo;
            $pago->save();

            $this->auditor->registrar('suscripcion.pago_rechazado', $pago, $actor, $pago->empresa_id,
                despues: ['comprobante' => $pago->codigo(), 'motivo' => $motivo]);

            return $pago;
        }, attempts: 3);
    }

    /**
     * Activa los extras pagados (la cantidad pedida, no una suma: el envío era el único
     * comprobante en revisión y mientras tanto la empresa no puede cambiar sus extras).
     * El vencimiento no se mueve.
     */
    private function aprobarExtras(User $actor, Suscripcion $suscripcion, PagoSaas $pago): PagoSaas
    {
        $antes = ['sucursales_extra' => $suscripcion->sucursales_extra, 'paquetes_usuarios' => $suscripcion->paquetes_usuarios];
        $suscripcion->sucursales_extra = (int) $pago->sucursales_extra;
        $suscripcion->paquetes_usuarios = (int) $pago->paquetes_usuarios;
        $suscripcion->save();

        $pago->estado = EstadoPagoSaas::Aprobado;
        $pago->revisado_por = $actor->id;
        $pago->revisado_en = Carbon::now();
        $pago->vencimiento_anterior = $suscripcion->vencimiento;
        $pago->vencimiento_nuevo = $suscripcion->vencimiento;
        $pago->save();

        $this->auditor->registrar('suscripcion.extras_aprobados', $pago, $actor, $pago->empresa_id, $antes, [
            'sucursales_extra' => $suscripcion->sucursales_extra, 'paquetes_usuarios' => $suscripcion->paquetes_usuarios,
            'comprobante' => $pago->codigo(), 'importe' => (string) $pago->importe,
        ]);

        return $pago;
    }

    /**
     * @return array{0: Suscripcion, 1: PagoSaas}
     */
    private function bloquear(int $pagoId): array
    {
        $referencia = PagoSaas::withoutGlobalScope(EmpresaScope::class)->findOrFail($pagoId, ['id', 'empresa_id', 'suscripcion_id']);
        $suscripcion = Suscripcion::withoutGlobalScope(EmpresaScope::class)
            ->where('empresa_id', $referencia->empresa_id)->whereKey($referencia->suscripcion_id)->lockForUpdate()->firstOrFail();
        $pago = PagoSaas::withoutGlobalScope(EmpresaScope::class)->whereKey($pagoId)->lockForUpdate()->firstOrFail();

        return [$suscripcion, $pago];
    }
}
